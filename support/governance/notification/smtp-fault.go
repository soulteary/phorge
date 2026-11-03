// Owned SMTP fault fixture. No message is forwarded; DATA-drop mode models an unknown acknowledgement.
package main

import (
	"bufio"
	"bytes"
	"crypto/sha256"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"io"
	"net"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"
)

func run() error {
	listen := flag.String("listen", "127.0.0.1:0", "owned loopback only")
	directory := flag.String("directory", "", "owned private fixture directory")
	owner := flag.String("owner", "", "fixture marker")
	mode := flag.String("mode", "reject-envelope", "reject-envelope or drop-data-ack")
	flag.Parse()
	if *mode != "reject-envelope" && *mode != "drop-data-ack" {
		return errors.New("fixture mode refused")
	}
	host, _, err := net.SplitHostPort(*listen)
	if err != nil || host != "127.0.0.1" || !regexp.MustCompile(`^[a-f0-9]{64}$`).MatchString(*owner) {
		return errors.New("fixture arguments refused")
	}
	info, err := os.Lstat(*directory)
	if err != nil || !info.IsDir() || info.Mode()&os.ModeSymlink != 0 || info.Mode().Perm() != 0700 {
		return errors.New("fixture directory refused")
	}
	marker, err := os.Lstat(filepath.Join(*directory, "owner"))
	if err != nil || !marker.Mode().IsRegular() {
		return errors.New("fixture marker refused")
	}
	raw, err := os.ReadFile(filepath.Join(*directory, "owner"))
	if err != nil || !bytes.Equal(raw, []byte(*owner)) {
		return errors.New("fixture owner refused")
	}
	listener, err := net.Listen("tcp", *listen)
	if err != nil {
		return errors.New("fixture listen failed")
	}
	defer listener.Close()
	if err = json.NewEncoder(os.Stdout).Encode(map[string]any{"ready": true, "address": listener.Addr().String(), "accepts_data": *mode == "drop-data-ack", "mode": *mode}); err != nil {
		return err
	}
	if tcp, ok := listener.(*net.TCPListener); ok {
		if err = tcp.SetDeadline(time.Now().Add(30 * time.Second)); err != nil {
			return err
		}
	}
	connection, err := listener.Accept()
	if err != nil {
		return errors.New("fixture accept failed")
	}
	defer connection.Close()
	if err = connection.SetDeadline(time.Now().Add(30 * time.Second)); err != nil {
		return err
	}
	reader := bufio.NewReaderSize(connection, 4096)
	send := func(text string) error { _, err := fmt.Fprint(connection, text+"\r\n"); return err }
	if err = send("220 isolated rejection fixture"); err != nil {
		return err
	}
	rejected := false
	for commands := 0; commands < 32; commands++ {
		line, err := reader.ReadSlice('\n')
		if rejected && errors.Is(err, io.EOF) {
			return nil
		}
		if err != nil || len(line) > 4096 {
			return errors.New("fixture command refused")
		}
		fields := strings.Fields(string(line))
		if len(fields) == 0 {
			return errors.New("fixture empty command refused")
		}
		verb := strings.ToUpper(fields[0])
		switch verb {
		case "EHLO", "HELO", "MAIL", "RSET", "NOOP":
			err = send("250 isolated fixture")
		case "RCPT":
			if *mode == "drop-data-ack" {
				err = send("250 owned recipient")
				break
			}
			event, openErr := os.OpenFile(filepath.Join(*directory, "smtp-rejection-ready"), os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0600)
			if openErr != nil {
				return errors.New("fixture event refused")
			}
			_, writeErr := event.WriteString("pending-owned-rejection\n")
			closeErr := event.Close()
			if writeErr != nil || closeErr != nil {
				return errors.New("fixture event failed")
			}
			deadline := time.Now().Add(20 * time.Second)
			released := false
			for time.Now().Before(deadline) {
				release, readErr := os.ReadFile(filepath.Join(*directory, "smtp-rejection-release"))
				if readErr == nil && bytes.Equal(release, []byte(*owner)) {
					released = true
					break
				}
				time.Sleep(10 * time.Millisecond)
			}
			if !released {
				return errors.New("fixture release timed out")
			}
			err = send("451 temporary owned rejection; no message accepted")
			rejected = err == nil
		case "DATA":
			if *mode != "drop-data-ack" {
				err = send("554 no message data accepted")
				break
			}
			if err = send("354 end with dot"); err != nil {
				return err
			}
			digest := sha256.New()
			size := 0
			for {
				line, readErr := reader.ReadSlice('\n')
				if readErr != nil || len(line) > 4096 {
					return errors.New("fixture data refused")
				}
				if bytes.Equal(line, []byte(".\r\n")) {
					break
				}
				size += len(line)
				if size > 1048576 {
					return errors.New("fixture data limit")
				}
				_, _ = digest.Write(line)
			}
			event, openErr := os.OpenFile(filepath.Join(*directory, "smtp-data-ack-dropped"), os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0600)
			if openErr != nil {
				return errors.New("fixture data event refused")
			}
			writeErr := json.NewEncoder(event).Encode(map[string]any{"data_bytes": size, "wire_sha256": fmt.Sprintf("%x", digest.Sum(nil)), "acknowledgement_sent": false})
			closeErr := event.Close()
			if writeErr != nil || closeErr != nil {
				return errors.New("fixture data event failed")
			}
			return nil // Close the socket without a final DATA response.
		case "QUIT":
			return send("221 closing owned fixture")
		default:
			err = send("554 no message data accepted")
		}
		if err != nil {
			return errors.New("fixture reply failed")
		}
	}
	return errors.New("fixture command limit")
}
func main() {
	if err := run(); err != nil {
		fmt.Fprintln(os.Stderr, "owned SMTP fixture failed")
		os.Exit(1)
	}
}

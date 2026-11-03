// Disposable TLS transport for the native media fixture. No public bind or production configuration.
package main

import (
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/sha256"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/hex"
	"encoding/json"
	"encoding/pem"
	"fmt"
	"io"
	"log"
	"math/big"
	"net"
	"net/http"
	"net/http/httputil"
	"net/url"
	"os"
	"os/exec"
	"os/signal"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"
)

type configuration struct {
	Owner        string `json:"owner"`
	UpstreamPort int    `json:"upstream_port"`
	BrowserPort  int    `json:"browser_port"`
	Proof        string `json:"transport_proof"`
	Container    string `json:"podman_container,omitempty"`
	Connection   string `json:"podman_connection,omitempty"`
}

func fail() { fmt.Fprintln(os.Stderr, "owned_tls_fixture_refused"); os.Exit(1) }
func validHex(value string, size int) bool {
	return len(value) == size && regexp.MustCompile(`\A[a-f0-9]+\z`).MatchString(value)
}
func load(directory string) (configuration, error) {
	var config configuration
	real, err := filepath.EvalSymlinks(directory)
	if err != nil || real != directory || filepath.Dir(directory) != os.TempDir() || !regexp.MustCompile(`\Aphorge-media-wire-[a-f0-9]{24}\z`).MatchString(filepath.Base(directory)) {
		return config, fmt.Errorf("directory")
	}
	stat, err := os.Lstat(directory)
	if err != nil || !stat.IsDir() || stat.Mode().Perm() != 0700 {
		return config, fmt.Errorf("directory")
	}
	for _, name := range []string{"owner", "proxy.json"} {
		info, err := os.Lstat(filepath.Join(directory, name))
		if err != nil || !info.Mode().IsRegular() || info.Mode().Perm() != 0600 || info.Size() > 16384 {
			return config, fmt.Errorf("private")
		}
	}
	data, err := os.ReadFile(filepath.Join(directory, "proxy.json"))
	if err != nil {
		return config, err
	}
	decoder := json.NewDecoder(strings.NewReader(string(data)))
	decoder.DisallowUnknownFields()
	if decoder.Decode(&config) != nil || !validHex(config.Owner, 64) || !validHex(config.Proof, 64) || config.UpstreamPort < 1 || config.UpstreamPort > 65535 || config.BrowserPort < 0 || config.BrowserPort > 65535 {
		return config, fmt.Errorf("config")
	}
	if config.Container != "" && !regexp.MustCompile(`\Aphorge-governance-(?:media|http)[a-z0-9-]*-php\z`).MatchString(config.Container) {
		return config, fmt.Errorf("container")
	}
	if config.Connection != "" && (config.Container == "" || !regexp.MustCompile(`\A[a-zA-Z0-9._-]{1,128}\z`).MatchString(config.Connection)) {
		return config, fmt.Errorf("connection")
	}
	var extra any
	if decoder.Decode(&extra) != io.EOF {
		return config, fmt.Errorf("extra")
	}
	owner, err := os.ReadFile(filepath.Join(directory, "owner"))
	if err != nil || string(owner) != config.Owner {
		return config, fmt.Errorf("owner")
	}
	return config, nil
}
func certificate() (tls.Certificate, string, error) {
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return tls.Certificate{}, "", err
	}
	serial, err := rand.Int(rand.Reader, new(big.Int).Lsh(big.NewInt(1), 128))
	if err != nil {
		return tls.Certificate{}, "", err
	}
	template := &x509.Certificate{SerialNumber: serial, Subject: pkix.Name{CommonName: "Synthetic media fixture"},
		NotBefore: time.Now().Add(-time.Minute), NotAfter: time.Now().Add(4 * time.Hour),
		KeyUsage: x509.KeyUsageDigitalSignature, ExtKeyUsage: []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
		DNSNames: []string{"app.media.localhost", "files.media.localhost"}}
	der, err := x509.CreateCertificate(rand.Reader, template, template, &key.PublicKey, key)
	if err != nil {
		return tls.Certificate{}, "", err
	}
	digest := sha256.Sum256(der)
	return tls.Certificate{Certificate: [][]byte{der}, PrivateKey: key}, hex.EncodeToString(digest[:]), nil
}

type processConnection struct {
	net.Conn
	command *exec.Cmd
	once    sync.Once
}

func (connection *processConnection) Close() error {
	err := connection.Conn.Close()
	connection.once.Do(func() {
		if connection.command.Process != nil {
			connection.command.Process.Kill()
		}
		connection.command.Wait()
	})
	return err
}
func pipeDial(config configuration) func(context.Context, string, string) (net.Conn, error) {
	return func(ctx context.Context, network, address string) (net.Conn, error) {
		args := []string{}
		if config.Connection != "" {
			args = append(args, "--connection", config.Connection)
		}
		args = append(args, "exec", "-i", "-e", "PHORGE_GOVERNANCE_ISOLATED_TEST=1", config.Container, "/tmp/phorge-media-wire-tls-proxy", "--stdio", strconv.Itoa(config.UpstreamPort))
		if err := ctx.Err(); err != nil {
			return nil, err
		}
		command := exec.Command("podman", args...)
		command.Stderr = io.Discard
		input, err := command.StdinPipe()
		if err != nil {
			return nil, err
		}
		output, err := command.StdoutPipe()
		if err != nil {
			input.Close()
			return nil, err
		}
		if err = command.Start(); err != nil {
			input.Close()
			output.Close()
			return nil, err
		}
		client, bridge := net.Pipe()
		go func() { io.Copy(input, bridge); input.Close() }()
		go func() { io.Copy(bridge, output); output.Close(); bridge.Close() }()
		return &processConnection{Conn: client, command: command}, nil
	}
}
func stdio(port string) error {
	if os.Getenv("PHORGE_GOVERNANCE_ISOLATED_TEST") != "1" || !regexp.MustCompile(`\A[1-9][0-9]{0,4}\z`).MatchString(port) {
		return fmt.Errorf("scope")
	}
	number, err := strconv.Atoi(port)
	if err != nil || number > 65535 {
		return fmt.Errorf("port")
	}
	connection, err := net.DialTimeout("tcp4", "127.0.0.1:"+port, 5*time.Second)
	if err != nil {
		return err
	}
	defer connection.Close()
	go func() {
		io.Copy(connection, os.Stdin)
		if tcp, ok := connection.(*net.TCPConn); ok {
			tcp.CloseWrite()
		}
	}()
	_, err = io.Copy(os.Stdout, connection)
	return err
}
func main() {
	if len(os.Args) == 3 && os.Args[1] == "--stdio" {
		if stdio(os.Args[2]) != nil {
			fail()
		}
		return
	}
	if len(os.Args) != 2 || os.Getenv("PHORGE_GOVERNANCE_ISOLATED_TEST") != "1" {
		fail()
	}
	directory := os.Args[1]
	config, err := load(directory)
	if err != nil {
		fail()
	}
	listener, err := net.Listen("tcp4", "127.0.0.1:0")
	if err != nil {
		fail()
	}
	cert, digest, err := certificate()
	if err != nil {
		listener.Close()
		fail()
	}
	port := listener.Addr().(*net.TCPAddr).Port
	browserPort := config.BrowserPort
	if browserPort == 0 {
		browserPort = port
	}
	upstream := &url.URL{Scheme: "http", Host: fmt.Sprintf("127.0.0.1:%d", config.UpstreamPort)}
	proxy := httputil.NewSingleHostReverseProxy(upstream)
	transport := &http.Transport{Proxy: nil, DialContext: (&net.Dialer{Timeout: 5 * time.Second}).DialContext,
		ResponseHeaderTimeout: 15 * time.Second, MaxIdleConns: 4, IdleConnTimeout: 5 * time.Second}
	if config.Container != "" {
		transport.DialContext = pipeDial(config)
		transport.DisableKeepAlives = true
	}
	proxy.Transport = transport
	defer transport.CloseIdleConnections()
	director := proxy.Director
	proxy.Director = func(request *http.Request) {
		director(request)
		for _, key := range []string{"X-Phorge-Isolated-TLS", "Forwarded", "X-Forwarded-For", "X-Forwarded-Host", "X-Forwarded-Proto"} {
			request.Header.Del(key)
		}
		request.Header.Set("X-Phorge-Isolated-TLS", config.Proof)
	}
	proxy.ErrorLog = log.New(io.Discard, "", 0)
	proxy.ErrorHandler = func(writer http.ResponseWriter, request *http.Request, err error) {
		http.Error(writer, "fixture unavailable", 502)
	}
	allowed := map[string]bool{fmt.Sprintf("app.media.localhost:%d", browserPort): true, fmt.Sprintf("files.media.localhost:%d", browserPort): true}
	if browserPort == 443 {
		allowed = map[string]bool{"app.media.localhost": true, "files.media.localhost": true}
	}
	handler := http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		if request.TLS == nil || !allowed[request.Host] || (request.TLS.ServerName != "app.media.localhost" && request.TLS.ServerName != "files.media.localhost") {
			http.Error(writer, "fixture unavailable", 403)
			return
		}
		requestHost := request.Host
		if browserPort != 443 {
			requestHost, _, _ = net.SplitHostPort(request.Host)
		}
		if requestHost != request.TLS.ServerName {
			http.Error(writer, "fixture unavailable", 403)
			return
		}
		host, _, err := net.SplitHostPort(request.RemoteAddr)
		if err != nil || host != "127.0.0.1" {
			http.Error(writer, "fixture unavailable", 403)
			return
		}
		request.Body = http.MaxBytesReader(writer, request.Body, 16384)
		proxy.ServeHTTP(writer, request)
	})
	server := &http.Server{Handler: handler, ReadHeaderTimeout: 5 * time.Second, ReadTimeout: 20 * time.Second, WriteTimeout: 30 * time.Second,
		IdleTimeout: 10 * time.Second, MaxHeaderBytes: 16384, ErrorLog: log.New(io.Discard, "", 0)}
	// Export the public leaf only. Private signing material remains memory-only.
	publicPath := filepath.Join(directory, "tls-leaf.pem")
	publicFile, err := os.OpenFile(publicPath, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0600)
	if err != nil {
		listener.Close()
		fail()
	}
	publicBytes := pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: cert.Certificate[0]})
	written, writeErr := publicFile.Write(publicBytes)
	closePublicErr := publicFile.Close()
	if writeErr != nil || closePublicErr != nil || written != len(publicBytes) {
		listener.Close()
		fail()
	}
	ready, _ := json.Marshal(map[string]any{"tls_port": port, "browser_port": browserPort, "certificate_sha256": digest, "public_bind": false})
	temporary := filepath.Join(directory, "proxy-ready.tmp")
	file, err := os.OpenFile(temporary, os.O_WRONLY|os.O_CREATE|os.O_EXCL, 0600)
	if err != nil {
		listener.Close()
		fail()
	}
	_, err = file.Write(ready)
	closeErr := file.Close()
	if err != nil || closeErr != nil || os.Rename(temporary, filepath.Join(directory, "proxy-ready.json")) != nil {
		listener.Close()
		fail()
	}
	shutdown := make(chan os.Signal, 1)
	signal.Notify(shutdown, syscall.SIGTERM, syscall.SIGINT)
	go func() {
		<-shutdown
		ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer cancel()
		server.Shutdown(ctx)
	}()
	err = server.Serve(tls.NewListener(listener, &tls.Config{Certificates: []tls.Certificate{cert}, MinVersion: tls.VersionTLS12}))
	if err != nil && err != http.ErrServerClosed {
		fail()
	}
}

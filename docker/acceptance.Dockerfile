ARG GO_BASE_IMAGE
ARG PHORGE_BASE_IMAGE=phorge-acceptance:base
FROM ${GO_BASE_IMAGE} AS go
FROM ${PHORGE_BASE_IMAGE}
ARG DEBIAN_SNAPSHOT=20261006T000000Z
RUN apt-get update && apt-get install -y --no-install-recommends python3 openssl gcc libc6-dev \
    && rm -rf /var/lib/apt/lists/*
COPY --from=go /usr/local/go /usr/local/go
ENV PATH=/usr/local/go/bin:$PATH CGO_ENABLED=1 PYTHONDONTWRITEBYTECODE=1
RUN git config --global --add safe.directory /work/phorge \
    && git config --global --add safe.directory /work/gorge
ENTRYPOINT ["python3", "/work/phorge/deploy/acceptance/run.py"]

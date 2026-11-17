
FROM debian:trixie

ARG DEBIAN_FRONTEND=noninteractive
ARG ASTERISK_VERSION=22.11.0

ENV ASTERISK_VERSION=${ASTERISK_VERSION}

# Build dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    build-essential \
    ca-certificates \
    wget \
    curl \
    pkg-config \
    libedit-dev \
    libjansson-dev \
    libsqlite3-dev \
    uuid-dev \
    libxml2-dev \
    libncurses-dev \
    libssl-dev \
    libcurl4-openssl-dev \
    libnewt-dev \
    libspandsp-dev \
    libspeexdsp-dev \
    libogg-dev \
    libvorbis-dev \
    libopus-dev \
    libpq-dev \
    libsrtp2-dev \
    liburiparser-dev \
    python3 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /usr/src

# Download Asterisk source
RUN wget -O asterisk.tar.gz \
    https://downloads.asterisk.org/pub/telephony/asterisk/releases/asterisk-${ASTERISK_VERSION}.tar.gz \
    && tar xzf asterisk.tar.gz \
    && rm asterisk.tar.gz

WORKDIR /usr/src/asterisk-${ASTERISK_VERSION}

# Configure Asterisk
RUN ./configure \
    --with-pjproject-bundled \
    --with-jansson-bundled \
    --with-postgres

# Generate default menuselect options
RUN make menuselect.makeopts

# Select required modules
RUN menuselect/menuselect \
    --enable chan_pjsip \
    --enable res_pjsip \
    --enable res_pjsip_authenticator_digest \
    --enable res_pjsip_endpoint_identifier_ip \
    --enable res_pjsip_registrar \
    --enable res_pjsip_session \
    --enable res_config_pgsql \
    --enable pbx_config \
    --enable pbx_realtime \
    menuselect.makeopts

# Compile and install
RUN make -j$(nproc) \
    && make install \
    && ldconfig

# Create Asterisk user
RUN groupadd --system asterisk \
    && useradd --system \
       --gid asterisk \
       --home-dir /var/lib/asterisk \
       --shell /usr/sbin/nologin \
       asterisk

# Create runtime directories and set permissions
RUN mkdir -p \
    /var/lib/asterisk \
    /var/log/asterisk \
    /var/spool/asterisk \
    /var/run/asterisk \
    /etc/asterisk \
    && chown -R asterisk:asterisk \
       /var/lib/asterisk \
       /var/log/asterisk \
       /var/spool/asterisk \
       /var/run/asterisk \
       /etc/asterisk

WORKDIR /var/lib/asterisk

USER asterisk:asterisk

STOPSIGNAL SIGTERM

CMD ["asterisk", "-f", "-U", "asterisk", "-G", "asterisk", "-vvv"]


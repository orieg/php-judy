FROM php:8.4-cli

# Install dependencies for judy extension
# No libjudy-dev: the bundled libJudy (libjudy/) is the default build.
RUN apt-get update && apt-get install -y \
    build-essential \
    git \
    valgrind

# Copy the extension source code into the container
COPY . /usr/src/php-judy
WORKDIR /usr/src/php-judy

# Build and install the judy extension (clean first to remove stale build artifacts)
RUN find . -name "*.lo" -delete && find . -name "*.dep" -delete && rm -f Makefile Makefile.objects Makefile.fragments \
    && phpize \
    && ./configure \
    && make \
    && make install

# Enable the judy extension
RUN docker-php-ext-enable judy

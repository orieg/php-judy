FROM php:8.1-cli

# Install dependencies for judy extension
RUN apt-get update && apt-get install -y \
    build-essential \
    git \
    libjudy-dev \
    valgrind

# Copy the extension source code into the container
COPY . /usr/src/php-judy
WORKDIR /usr/src/php-judy

# Build and install the judy extension
RUN phpize \
    && ./configure --with-judy=/usr \
    && make \
    && make install

# Enable the judy extension
RUN docker-php-ext-enable judy

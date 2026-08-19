# Differential fuzzing harness for libJudy — issue #142 Stage 4.
#
# Builds against a SYSTEM libJudy by default (/opt/homebrew on macOS, /usr
# elsewhere). Point JUDY_PREFIX at any prefix that has include/Judy.h and
# lib/libJudy.{a,so,dylib} — e.g. at the vendored bundled build once #142
# Stage 1 lands, or at a scratch build made by validation/build-stock.sh.
#
#   make                          # plain -O2 harness
#   make diffuzz-san              # harness instrumented with ASan+UBSan
#   make smoke                    # build + fixed-seed smoke run
#   make JUDY_PREFIX=/some/prefix

UNAME_S := $(shell uname -s)
ifeq ($(UNAME_S),Darwin)
JUDY_PREFIX ?= /opt/homebrew
else
JUDY_PREFIX ?= /usr
endif

CXX      ?= c++
STD       = -std=c++17
WARN      = -Wall -Wextra -Werror
OPT      ?= -O2 -g
CPPFLAGS += -I$(JUDY_PREFIX)/include
LDFLAGS  += -L$(JUDY_PREFIX)/lib
LDLIBS    = -lJudy

# MULTIGET=1: the library under test is a bundled-tree build that has
# JudyLMultiGet (#142 patch O5) -- enables the batched-vs-serial oracle
# mode.  Leave unset for stock/system libJudy, which lacks the symbol.
ifeq ($(MULTIGET),1)
CPPFLAGS += -DHAVE_JUDYL_MULTIGET
endif
SAN       = -fsanitize=address,undefined -fno-sanitize-recover=all \
            -fno-omit-frame-pointer

all: diffuzz

diffuzz: diffuzz.cpp
	$(CXX) $(STD) $(OPT) $(WARN) $(CPPFLAGS) -o $@ diffuzz.cpp $(LDFLAGS) $(LDLIBS)

# Harness-side sanitizers only: the library is whatever JUDY_PREFIX holds.
# To sanitize the library itself, build one with validation/build-stock.sh
# (CFLAGS containing -fsanitize=address) and point JUDY_PREFIX at it.
diffuzz-san: diffuzz.cpp
	$(CXX) $(STD) -O1 -g $(SAN) $(WARN) $(CPPFLAGS) -o $@ diffuzz.cpp $(LDFLAGS) $(LDLIBS)

smoke: diffuzz
	./diffuzz smoke

soak: diffuzz
	./diffuzz soak 60

clean:
	rm -f diffuzz diffuzz-san

.PHONY: all smoke soak clean

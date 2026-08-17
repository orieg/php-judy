# Step-0 feasibility spike for issue #83 (shared-memory Judy arena).
# NOT part of the php-judy build. Nothing here is shipped (absent from package.xml).
#
# IMPORTANT (gate 1 finding): overriding JudyMalloc/JudyFree requires that the
# override be visible to libJudy's own internal calls.
#   * Linux/ELF : works against the stock shared libJudy.so AND the static .a
#   * macOS/Mach-O: works ONLY against the static archive libJudy.a. The
#     two-level namespace binds libJudy.dylib's internal calls to its own
#     definition; -flat_namespace does not change this.
# So we link the static archive by default on macOS.

UNAME_S := $(shell uname -s)
ifeq ($(UNAME_S),Darwin)
  JUDY_PREFIX ?= /opt/homebrew
else
  JUDY_PREFIX ?= /usr
endif

CC      ?= cc
CFLAGS  ?= -std=c11 -O2 -g -Wall -Wextra -Wno-unused-result
ifeq ($(UNAME_S),Darwin)
  FEATURE_MACROS = -D_DARWIN_C_SOURCE
else
  # glibc hides MAP_ANONYMOUS, robust-mutex constants, etc. under strict -std=c11
  FEATURE_MACROS = -D_GNU_SOURCE
endif

CPPFLAGS = -I$(JUDY_PREFIX)/include $(FEATURE_MACROS)

ifeq ($(UNAME_S),Darwin)
  JUDY_LIB = $(JUDY_PREFIX)/lib/libJudy.a
else
  JUDY_LIB = -L$(JUDY_PREFIX)/lib -lJudy
  # On Linux prefer the static archive when present for a like-for-like link.
  ifneq ($(wildcard /usr/lib/x86_64-linux-gnu/libJudy.a),)
    JUDY_LIB = /usr/lib/x86_64-linux-gnu/libJudy.a
  endif
  ifneq ($(wildcard /usr/lib/aarch64-linux-gnu/libJudy.a),)
    JUDY_LIB = /usr/lib/aarch64-linux-gnu/libJudy.a
  endif
  LDLIBS_EXTRA = -lpthread -lrt
endif

GATES = gate1_correctness gate2_fork gate3_lock gate4_profile gate5_crash

all: $(GATES)

gate%: gate%.c shm_arena.c shm_arena.h
	$(CC) $(CFLAGS) $(CPPFLAGS) -o $@ $< shm_arena.c $(JUDY_LIB) $(LDLIBS_EXTRA)

# Address/UB sanitizer build of the correctness gate.
gate1_asan: gate1_correctness.c shm_arena.c shm_arena.h
	$(CC) $(CFLAGS) $(CPPFLAGS) -fsanitize=address,undefined -fno-omit-frame-pointer \
	  -o $@ $< shm_arena.c $(JUDY_LIB) $(LDLIBS_EXTRA)

.PHONY: all clean run run1 run2 run3 run4 run5 asan
run: run1 run2 run3 run4 run5

run1: gate1_correctness ; @./gate1_correctness
run2: gate2_fork        ; @./gate2_fork
run3: gate3_lock        ; @./gate3_lock
run4: gate4_profile     ; @./gate4_profile
run5: gate5_crash       ; @./gate5_crash

asan: gate1_asan ; @./gate1_asan

clean:
	rm -f $(GATES) gate1_asan *.o

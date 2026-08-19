dnl
dnl $Id$
dnl

PHP_ARG_WITH(judy, for Judy support,
[  --with-judy[=DIR]       Include Judy support. Without DIR (or with "yes"/
                          "bundled") the bundled libJudy under libjudy/ is
                          compiled in [default=BUNDLED]. Pass the install
                          prefix of a system libJudy as DIR to dynamically
                          link against it instead.], yes)

PHP_ARG_ENABLE(judy-debug-mirror, whether to enable Judy consistency assertions,
[  --enable-judy-debug-mirror
                          Compile the internal key_index/value-store
                          consistency assertions. Aborts on violation; for
                          development and CI only, never for a release
                          build. [default=no]], no, no)

judy_sources="judy_handlers.c judy_arrayaccess.c judy_iterator.c"

dnl # The bundled libJudy (libjudy/, pristine Judy-1.0.5 plus the documented
dnl # patch series -- see libjudy/PATCHES.md). The wrappers compile each
dnl # JudyCommon TU once per variant (JUDY1/JUDYL, some twice with extra
dnl # defines); the enumeration follows upstream's per-variant Makefile.am
dnl # (issue #142). Judy1Tables.c/JudyLTables.c are pre-generated (JU_64BIT).
judy_bundled_sources="\
  libjudy/src/JudyCommon/JudyMalloc.c \
  libjudy/src/JudyCommon/JudyNoInline.c \
  libjudy/src/JudySL/JudySL.c \
  libjudy/src/JudyHS/JudyHS.c \
  libjudy/src/Judy1/Judy1Tables.c \
  libjudy/src/JudyL/JudyLTables.c \
  libjudy/src/wrappers/judy_static_asserts.c \
  libjudy/src/wrappers/Judy1Test.c \
  libjudy/src/wrappers/Judy1TestInline.c \
  libjudy/src/wrappers/Judy1Set.c \
  libjudy/src/wrappers/Judy1SetArray.c \
  libjudy/src/wrappers/Judy1Unset.c \
  libjudy/src/wrappers/Judy1Cascade.c \
  libjudy/src/wrappers/Judy1Decascade.c \
  libjudy/src/wrappers/Judy1Count.c \
  libjudy/src/wrappers/Judy1ByCount.c \
  libjudy/src/wrappers/Judy1CreateBranch.c \
  libjudy/src/wrappers/Judy1InsertBranch.c \
  libjudy/src/wrappers/Judy1First.c \
  libjudy/src/wrappers/Judy1Next.c \
  libjudy/src/wrappers/Judy1Prev.c \
  libjudy/src/wrappers/Judy1NextEmpty.c \
  libjudy/src/wrappers/Judy1PrevEmpty.c \
  libjudy/src/wrappers/Judy1FreeArray.c \
  libjudy/src/wrappers/Judy1MallocIF.c \
  libjudy/src/wrappers/Judy1MemActive.c \
  libjudy/src/wrappers/Judy1MemUsed.c \
  libjudy/src/wrappers/JudyLGet.c \
  libjudy/src/wrappers/JudyLGetInline.c \
  libjudy/src/wrappers/JudyLMultiGet.c \
  libjudy/src/wrappers/JudyLIns.c \
  libjudy/src/wrappers/JudyLInsArray.c \
  libjudy/src/wrappers/JudyLDel.c \
  libjudy/src/wrappers/JudyLCascade.c \
  libjudy/src/wrappers/JudyLDecascade.c \
  libjudy/src/wrappers/JudyLCount.c \
  libjudy/src/wrappers/JudyLByCount.c \
  libjudy/src/wrappers/JudyLCreateBranch.c \
  libjudy/src/wrappers/JudyLInsertBranch.c \
  libjudy/src/wrappers/JudyLFirst.c \
  libjudy/src/wrappers/JudyLNext.c \
  libjudy/src/wrappers/JudyLPrev.c \
  libjudy/src/wrappers/JudyLNextEmpty.c \
  libjudy/src/wrappers/JudyLPrevEmpty.c \
  libjudy/src/wrappers/JudyLFreeArray.c \
  libjudy/src/wrappers/JudyLMallocIF.c \
  libjudy/src/wrappers/JudyLMemActive.c \
  libjudy/src/wrappers/JudyLMemUsed.c"

if test "$PHP_JUDY" != "no"; then

  dnl # Bundled unless a real DIR (or the legacy "autodetect") was given.
  if test "$PHP_JUDY" = "yes" || test "$PHP_JUDY" = "bundled"; then
    judy_bundled=yes
  else
    judy_bundled=no
  fi

  AC_MSG_CHECKING([for Judy library to use])
  if test "$judy_bundled" = "yes"; then
    AC_MSG_RESULT([bundled (libjudy/)])
  else
    dnl # --with-judy=DIR -> system-lib mode: search prefix, then defaults.
    SEARCH_PATH="/usr/local /usr"
    SEARCH_FOR="/include/Judy.h"
    if test -r $PHP_JUDY/$SEARCH_FOR; then
      JUDY_DIR=$PHP_JUDY
    else
      for i in $SEARCH_PATH ; do
        if test -r $i/$SEARCH_FOR; then
          JUDY_DIR=$i
        fi
      done
    fi

    if test -z "$JUDY_DIR"; then
      AC_MSG_RESULT([not found])
      AC_MSG_ERROR([Please install the Judy libraries, or build the bundled copy with --with-judy=bundled])
    else
      AC_MSG_RESULT([found in $JUDY_DIR])
    fi

    PHP_ADD_INCLUDE($JUDY_DIR/include)

    dnl # --with-judy=DIR -> check for lib and symbol presence
    LIBNAME=Judy
    LIBSYMBOL=Judy1Set

    PHP_CHECK_LIBRARY($LIBNAME, $LIBSYMBOL,
    [
      PHP_ADD_LIBRARY_WITH_PATH($LIBNAME, $JUDY_DIR/$PHP_LIBDIR, JUDY_SHARED_LIBADD)
      AC_DEFINE(HAVE_JUDYLIB,1,[ ])
    ],[
      AC_MSG_ERROR([wrong Judy lib version or lib not found using -L$JUDY_DIR/$PHP_LIBDIR -lJudy])
    ],[
      -L$JUDY_DIR/$PHP_LIBDIR -lJudy
    ])
  fi

  PHP_INSTALL_HEADERS([ext/judy], [php_judy.h judy_handlers.h judy_arrayaccess.h judy_iterator.h])

  dnl # Opt-in internal consistency assertions (see JUDY_ASSERT_MIRROR).
  dnl # Global CFLAGS on purpose: the assertions live in the extension
  dnl # sources, so this works identically in bundled and system-lib mode.
  if test "$PHP_JUDY_DEBUG_MIRROR" = "yes"; then
    AC_MSG_NOTICE([Judy mirror consistency assertions enabled])
    CFLAGS="$CFLAGS -DJUDY_DEBUG_MIRROR"
  fi

  dnl # Performance optimizations for production builds.
  dnl # PHP normalises PHP_DEBUG to 1/0 before an extension's config.m4 runs, so
  dnl # a debug build arrives here as PHP_DEBUG=1, not "yes". Testing only
  dnl # against "yes" therefore matched debug builds too and forced -DNDEBUG in,
  dnl # which Zend/zend_portability.h rejects outright ("NDEBUG must not be
  dnl # defined when ZEND_DEBUG is enabled") -- the extension could not be built
  dnl # against a debug PHP at all. Both spellings are accepted here.
  dnl #
  dnl # These flags reach ONLY the extension's own sources: the vendored
  dnl # libJudy units below carry their own per-source flags that override
  dnl # these (a later -O2 beats this -O3, -fno-lto beats -flto). Stock
  dnl # libJudy at gcc -O3 silently loses Judy1 keys (#131) -- never let
  dnl # these flags reach the vendored units.
  if test "$PHP_DEBUG" != "yes" && test "$PHP_DEBUG" != "1"; then
    dnl # Add optimization flags for production
    CFLAGS="$CFLAGS -O3"
    CFLAGS="$CFLAGS -fomit-frame-pointer"
    CFLAGS="$CFLAGS -DNDEBUG"
    dnl # Additional performance flags
    CFLAGS="$CFLAGS -fno-common"
    CFLAGS="$CFLAGS -funroll-loops"
    CFLAGS="$CFLAGS -flto"
  fi

  PHP_NEW_EXTENSION(judy, php_judy.c $judy_sources, $ext_shared)
  PHP_ADD_EXTENSION_DEP(judy, json)

  if test "$judy_bundled" = "yes"; then
    dnl # ---------------------------------------------------------------
    dnl # Bundled libJudy build integration.
    dnl # ---------------------------------------------------------------

    dnl # The committed Judy1Tables.c/JudyLTables.c were generated under
    dnl # JU_64BIT and are wrong for 32-bit words. Refuse rather than
    dnl # miscompile; a system libJudy still works on such targets.
    AC_MSG_CHECKING([whether long is 64-bit (required by the bundled libJudy)])
    AC_COMPILE_IFELSE([AC_LANG_PROGRAM([[
      typedef char judy_long_is_64bit[sizeof(long) == 8 ? 1 : -1];
    ]], [[]])], [
      AC_MSG_RESULT([yes])
    ], [
      AC_MSG_RESULT([no])
      AC_MSG_ERROR([the bundled libJudy currently supports 64-bit targets only (its pre-generated tables assume JU_64BIT); use --with-judy=DIR to link a system libJudy instead])
    ])

    dnl # Vendored-only compile flags. These are LOAD-BEARING, not a
    dnl # preference: stock libJudy's out-of-bounds immediate-index copy
    dnl # (#131) miscompiles under aggressive loop optimization -- gcc
    dnl # silently loses Judy1/BITSET keys -- and LTO would let the link
    dnl # step re-optimize across the same boundary. PHP_ADD_SOURCES_X
    dnl # places these AFTER the global $(CFLAGS_CLEAN) on each vendored
    dnl # compile line, and with gcc/clang the later flag wins, so the
    dnl # global -O3/-flto never take effect for these units. The global
    dnl # -funroll-loops needs its own negation: a later -O2 does NOT
    dnl # cancel it, and measured on gcc 13/14 it is -funroll-loops (at
    dnl # -O2 or -O3 alike) that makes gcc exploit the UB and truncate the
    dnl # copy -- caught by tests/bitset_immed_cascade_integrity_001.phpt
    dnl # (the #131 detector) when this flag set briefly lacked
    dnl # -fno-unroll-loops. JU_64BIT lives here, not in the wrapper
    dnl # files, so a 32-bit build remains possible later. No other
    dnl # platform define is needed on unix: JudyPrivate.h keys only on
    dnl # JU_WIN (Windows) and the Itanium JU_*_IPF defines beyond it.
    judy_vendor_cflags="-O2 -fno-lto -fno-unroll-loops -DJU_64BIT"

    dnl # -mpopcnt: probe compiler acceptance (x86-64 gcc/clang accept it,
    dnl # other targets reject it, so acceptance doubles as the arch test).
    dnl # The flag activates patch O1's __POPCNT__ arm in JudyPrivate.h
    dnl # (hardware popcount for j__udyCountBits{B,L} -- see
    dnl # libjudy/PATCHES.md, #142); without it those builds use the
    dnl # portable SWAR code, and arm64 selects its own popcount arm
    dnl # with no flag at all.
    AC_MSG_CHECKING([whether $CC accepts -mpopcnt])
    judy_save_CFLAGS="$CFLAGS"
    CFLAGS="$CFLAGS -Werror -mpopcnt"
    AC_COMPILE_IFELSE([AC_LANG_PROGRAM([[]], [[]])], [
      judy_have_mpopcnt=yes
    ], [
      judy_have_mpopcnt=no
    ])
    CFLAGS="$judy_save_CFLAGS"
    AC_MSG_RESULT([$judy_have_mpopcnt])
    if test "$judy_have_mpopcnt" = "yes"; then
      judy_vendor_cflags="$judy_vendor_cflags -mpopcnt"
    fi

    dnl # Bundled headers must win over any system Judy.h (prepend).
    PHP_ADD_INCLUDE([$ext_srcdir/libjudy/src], 1)
    PHP_ADD_INCLUDE([$ext_srcdir/libjudy/src/JudyCommon], 1)
    PHP_ADD_INCLUDE([$ext_srcdir/libjudy/src/Judy1], 1)
    PHP_ADD_INCLUDE([$ext_srcdir/libjudy/src/JudyL], 1)

    dnl # Attach the vendored sources with their own flags. Mirrors what
    dnl # PHP_NEW_EXTENSION does internally for each build mode (the same
    dnl # mechanism php-src's ext/opcache uses for its IR sources).
    if test "$ext_shared" = "yes" || test "$ext_shared" = "shared"; then
      PHP_ADD_SOURCES_X([$ext_dir], [$judy_bundled_sources], [$judy_vendor_cflags], [shared_objects_judy], [yes])
    else
      PHP_ADD_SOURCES([$ext_dir], [$judy_bundled_sources], [$judy_vendor_cflags])
    fi

    PHP_ADD_BUILD_DIR([$ext_builddir/libjudy/src/JudyCommon], 1)
    PHP_ADD_BUILD_DIR([$ext_builddir/libjudy/src/Judy1], 1)
    PHP_ADD_BUILD_DIR([$ext_builddir/libjudy/src/JudyL], 1)
    PHP_ADD_BUILD_DIR([$ext_builddir/libjudy/src/JudySL], 1)
    PHP_ADD_BUILD_DIR([$ext_builddir/libjudy/src/JudyHS], 1)
    PHP_ADD_BUILD_DIR([$ext_builddir/libjudy/src/wrappers], 1)

    AC_DEFINE(HAVE_JUDYLIB, 1, [ ])
    AC_DEFINE(HAVE_JUDY_BUNDLED, 1, [Judy support uses the bundled libJudy])
  fi

  PHP_SUBST(JUDY_SHARED_LIBADD)

fi

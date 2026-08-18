dnl
dnl $Id$
dnl

PHP_ARG_WITH(judy, for Judy support,
[  --with-judy[=DIR]       Include Judy support.
                          DIR is the Judy install prefix [default=BUNDLED]])

PHP_ARG_ENABLE(judy-debug-mirror, whether to enable Judy consistency assertions,
[  --enable-judy-debug-mirror
                          Compile the internal key_index/value-store
                          consistency assertions. Aborts on violation; for
                          development and CI only, never for a release
                          build. [default=no]], no, no)

judy_sources="judy_handlers.c judy_arrayaccess.c judy_iterator.c"

if test "$PHP_JUDY" != "no"; then

  dnl # --with-judy -> check with-path
  SEARCH_PATH="/usr/local /usr"
  SEARCH_FOR="/include/Judy.h"
  AC_MSG_CHECKING([for Judy library to use])
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
    AC_MSG_ERROR([Please install the Judy libraries])
  else
    AC_MSG_RESULT([found in $JUDY_DIR])
  fi

  PHP_ADD_INCLUDE($JUDY_DIR/include)

  dnl # --with-judy -> check for lib and symbol presence
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

  PHP_INSTALL_HEADERS([ext/judy], [php_judy.h judy_handlers.h judy_arrayaccess.h judy_iterator.h])

  dnl # Opt-in internal consistency assertions (see JUDY_ASSERT_MIRROR).
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
  PHP_ADD_BUILD_DIR($ext_builddir/lib, 1)
  PHP_SUBST(JUDY_SHARED_LIBADD)

fi

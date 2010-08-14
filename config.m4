dnl
dnl $Id$
dnl

judy_class_sources="lib/judy1.c"

PHP_ARG_WITH(judy, for Judy support,
[  --with-judy[=DIR]       Include Judy support])

if test "$PHP_JUDY" != "no"; then

  dnl # --with-judy -> check with-path
  SEARCH_PATH="/usr/local /usr"
  SEARCH_FOR="/include/Judy.h"
  if test -r $PHP_JUDY/$SEARCH_FOR; then
    AC_MSG_CHECKING([for judy files in $PHP_JUDY])
    JUDY_DIR=$PHP_JUDY
  else
    AC_MSG_CHECKING([for judy files in default path])
    for i in $SEARCH_PATH ; do
      if test -r $i/$SEARCH_FOR; then
        JUDY_DIR=$i
        AC_MSG_RESULT(found in $i)
      fi
    done
  fi

  if test -z "$JUDY_DIR"; then
    AC_MSG_RESULT([not found])
    AC_MSG_ERROR([Please install the judy libraries])
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
    AC_MSG_ERROR([wrong judy lib version or lib not found])
  ],[
    -L$JUDY_DIR/$PHP_LIBDIR -lJudy
  ])

  PHP_INSTALL_HEADERS([ext/judy], [php_judy.h lib/judy1.h])
  PHP_NEW_EXTENSION(judy, php_judy.c $judy_class_sources, $ext_shared)
  PHP_ADD_BUILD_DIR($ext_builddir/lib, 1)
  PHP_SUBST(JUDY_SHARED_LIBADD)

fi

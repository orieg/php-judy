/*
  +----------------------------------------------------------------------+
  | PHP Judy                                                             |
  +----------------------------------------------------------------------+
  | Copyright (c) 1997-2010 The PHP Group                                |
  +----------------------------------------------------------------------+
  | This source file is subject to version 3.01 of the PHP license,      |
  | that is bundled with this package in the file LICENSE, and is        |
  | available through the world-wide-web at the following url:           |
  | http://www.php.net/license/3_01.txt                                  |
  | If you did not receive a copy of the PHP license and are unable to   |
  | obtain it through the world-wide-web, please send a note to          |
  | license@php.net so we can mail you a copy immediately.               |
  +----------------------------------------------------------------------+
  | Author: Nicolas Brousse <nicolas@brousse.info>                       |
  +----------------------------------------------------------------------+
*/

#include "php_judy.h"
#include "judy_arrayaccess.h"

/* {{{ proto int Judy::offsetSet(mixed offset, mixed value)
 set the value at the given offset in the Judy Array */
PHP_METHOD(judy, offsetSet)
{
    JUDY_METHOD_GET_OBJECT

    /* calling the object's free() method */
    //zend_call_method_with_2_params(&object, NULL, NULL, "set", NULL);
}
/* }}} */

/* {{{ proto int Judy::offsetSet(mixed offset)
 set the value at the given offset in the Judy Array */
PHP_METHOD(judy, offsetUnset)
{
    JUDY_METHOD_GET_OBJECT

    /* calling the object's free() method */
    //zend_call_method_with_2_params(&object, NULL, NULL, "set", NULL);
}
/* }}} */

/* {{{ proto mixed Judy::offsetGet(mixed offset)
 set the value at the given offset in the Judy Array */
PHP_METHOD(judy, offsetGet)
{
    JUDY_METHOD_GET_OBJECT

    /* calling the object's free() method */
    //zend_call_method_with_2_params(&object, NULL, NULL, "set", NULL);
}
/* }}} */

/* {{{ proto int Judy::offsetExists(mixed offset)
 set the value at the given offset in the Judy Array */
PHP_METHOD(judy, offsetExists)
{
    JUDY_METHOD_GET_OBJECT

    /* calling the object's free() method */
    //zend_call_method_with_2_params(&object, NULL, NULL, "set", NULL);
}
/* }}} */

/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * End:
 * vim600: noet sw=4 ts=4 fdm=marker
 * vim<600: noet sw=4 ts=4
 */

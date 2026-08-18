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

/* {{{ proto void Judy::offsetSet(mixed offset, mixed value)
   Set the value at the given offset in the Judy Array */
PHP_METHOD(Judy, offsetSet)
{
	zval *offset, *value;

	ZEND_PARSE_PARAMETERS_START(2, 2)
		Z_PARAM_ZVAL(offset)
		Z_PARAM_ZVAL(value)
	ZEND_PARSE_PARAMETERS_END();

	/* ArrayAccess::offsetSet() is `void`, so nothing may be returned here:
	   returning a bool under an IS_VOID arginfo trips
	   zend_verify_internal_return_type() on a debug build. The helper's
	   FAILURE is not lost — every failure it can report has already thrown
	   (bad key type, NUL in key, over-long key, keyless append on a
	   string-keyed array) or is an allocation failure userland cannot act
	   on. judy_object_write_dimension() discards it the same way. */
	judy_object_write_dimension_helper(getThis(), offset, value);
}
/* }}} */

/* {{{ proto void Judy::offsetUnset(mixed offset)
   Unset the given offset in the Judy Array */
PHP_METHOD(Judy, offsetUnset)
{
	zval *offset;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_ZVAL(offset)
	ZEND_PARSE_PARAMETERS_END();

	/* `void`, as in offsetSet() above. The discarded bool was also actively
	   misleading: the helper reports SUCCESS for a no-op delete of an absent
	   key but FAILURE when neither backing array is allocated yet, so it read
	   as "false on a fresh Judy, true on a populated one" — internal
	   allocation state, not whether anything was unset. */
	judy_object_unset_dimension_helper(getThis(), offset);
}
/* }}} */

/* {{{ proto mixed Judy::offsetGet(mixed offset)
   Fetch the given offset in the Judy Array */
PHP_METHOD(Judy, offsetGet)
{
	zval *offset, result, *result_ptr;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_ZVAL(offset)
	ZEND_PARSE_PARAMETERS_END();

	result_ptr = judy_object_read_dimension_helper(getThis(), offset, &result);
	if (!result_ptr) {
		RETURN_FALSE;
	}
	RETURN_ZVAL(&result, 1, 0);
}
/* }}} */

/* {{{ proto int Judy::offsetExists(mixed offset)
   Check if the the given offset exists in the Judy Array */
PHP_METHOD(Judy, offsetExists)
{
	zval *offset;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_ZVAL(offset)
	ZEND_PARSE_PARAMETERS_END();

	if (judy_object_has_dimension_helper(getThis(), offset, 0)) {
		RETURN_TRUE;
	}
	RETURN_FALSE;
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

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

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php_judy.h"
#include "judy_handlers.h"
#include "judy_arrayaccess.h"
#include "judy_iterator.h"
#include "ext/spl/spl_iterators.h"

ZEND_DECLARE_MODULE_GLOBALS(judy)

/* declare judy class entry */
zend_class_entry *judy_ce;
zend_object_handlers judy_handlers;

	/* {{{ php_judy_init_globals
	*/
static void php_judy_init_globals(zend_judy_globals *judy_globals)
{
	judy_globals->max_length = 65536;
}
/* }}} */

/* {{{ judy_free_storage
   close all resources and the memory allocated for the object */
static void judy_object_free_storage(zend_object *object)
{
	judy_object *intern = php_judy_object(object);

	/* Clean up iterator state */
	zval_ptr_dtor(&intern->iterator_key);
	zval_ptr_dtor(&intern->iterator_data);

	/* Free the Judy array if __destruct didn't already */
	if (intern->array != NULL) {
		Word_t Rc_word;

		if (intern->type == TYPE_INT_TO_MIXED) {
			Word_t index = 0;
			Word_t *PValue;

			JLF(PValue, intern->array, index);
			while (PValue != NULL && PValue != PJERR) {
				zval *value = (zval *)*PValue;
				zval_ptr_dtor(value);
				efree(value);
				JLN(PValue, intern->array, index);
			}
			JLFA(Rc_word, intern->array);
		} else if (intern->type == TYPE_STRING_TO_MIXED) {
			uint8_t kindex[PHP_JUDY_MAX_LENGTH];
			Word_t *PValue;

			kindex[0] = '\0';
			JSLF(PValue, intern->array, kindex);
			while (PValue != NULL && PValue != PJERR) {
				zval *value = (zval *)*PValue;
				zval_ptr_dtor(value);
				efree(value);
				JSLN(PValue, intern->array, kindex);
			}
			JSLFA(Rc_word, intern->array);
		} else if (intern->type == TYPE_BITSET) {
			J1FA(Rc_word, intern->array);
		} else if (intern->type == TYPE_INT_TO_INT) {
			JLFA(Rc_word, intern->array);
		} else if (intern->type == TYPE_STRING_TO_INT) {
			JSLFA(Rc_word, intern->array);
		}
		intern->array = NULL;
	}

	zend_object_std_dtor(&intern->std);
}
/* }}} */

/* {{{ judy_object_new_ex
*/
zend_object *judy_object_new_ex(zend_class_entry *ce, judy_object **ptr)
{
	judy_object *intern;

	intern = ecalloc(1, sizeof(judy_object) + zend_object_properties_size(ce));
	if (ptr) {
		*ptr = intern;
	}

	intern->next_empty_is_valid = 1;
	intern->next_empty = 0;

	/* Initialize iterator state */
	ZVAL_UNDEF(&intern->iterator_key);
	ZVAL_UNDEF(&intern->iterator_data);
	intern->iterator_initialized = 0;

	zend_object_std_init(&(intern->std), ce);
	object_properties_init(&intern->std, ce);

	intern->std.handlers = &judy_handlers;
	return &intern->std;
}
/* }}} */

/* {{{ judy_object_new
*/
zend_object *judy_object_new(zend_class_entry *ce)
{
	return judy_object_new_ex(ce, NULL);
}
/* }}} */

PHP_JUDY_API zend_class_entry *php_judy_ce(void)
{
	return judy_ce;
}

/* {{{ PHP INI entries
*/
PHP_INI_BEGIN()
	STD_PHP_INI_ENTRY("judy.string.maxlength", "65536", PHP_INI_ALL, OnUpdateLong, max_length, zend_judy_globals, judy_globals)
PHP_INI_END()
/* }}} */

#define CHECK_ARRAY_AND_ARG_TYPE(_index_, _string_key_, _error_flag_, _return_)	\
	switch (intern->type) {					\
		case TYPE_BITSET:					\
		case TYPE_INT_TO_INT:				\
		case TYPE_INT_TO_MIXED:				\
			_index_ = zval_get_long(offset);\
			break;							\
		case TYPE_STRING_TO_INT:			\
		case TYPE_STRING_TO_MIXED:			\
			if (UNEXPECTED(Z_TYPE_P(offset) != IS_STRING)) {	\
				zend_throw_error(zend_ce_type_error, "Judy offset must be of type string for string-based arrays"); \
				_error_flag_ = 1; \
			} else {								\
				_string_key_ = offset;	\
			} \
			break;							\
		default:							\
			php_error_docref(NULL, E_WARNING, "invalid Judy Array type, please report");	\
			_return_;						\
	}

zval *judy_object_read_dimension_helper(zval *object, zval *offset, zval *rv) /* {{{ */
{
	zend_long index = 0;
	Word_t j_index;
	Pvoid_t *PValue = NULL;
	zval *pstring_key = NULL;
	judy_object *intern = php_judy_object(Z_OBJ_P(object));
	int error_flag = 0;

	if (intern->array == NULL) {
		return NULL;
	}

	CHECK_ARRAY_AND_ARG_TYPE(index, pstring_key, error_flag, return NULL);
	if (error_flag) {
		return NULL;
	}

	j_index = index;

	if (intern->type == TYPE_BITSET) {
		int     Rc_int;

		J1T(Rc_int, intern->array, j_index);
		ZVAL_BOOL(rv, Rc_int);
		return rv;
	}

	switch(intern->type) {
		case TYPE_INT_TO_INT:
		case TYPE_INT_TO_MIXED:
			JLG(PValue, intern->array, j_index);
			break;
		case TYPE_STRING_TO_INT:
		case TYPE_STRING_TO_MIXED:
			JSLG(PValue, intern->array, (uint8_t *)Z_STRVAL_P(pstring_key));
			break;
	}

	if (PValue != NULL && PValue != PJERR) {
		if (intern->type == TYPE_INT_TO_INT || intern->type == TYPE_STRING_TO_INT) {
			ZVAL_LONG(rv, (zend_long)*PValue);
		} else if (intern->type == TYPE_INT_TO_MIXED || intern->type == TYPE_STRING_TO_MIXED) {
			ZVAL_COPY(rv, (zval *)*PValue);
		}
		return rv;
	}
	return NULL;
}
/* }}} */

static zval *judy_object_read_dimension(zend_object *obj, zval *offset, int type, zval *rv)
{
    zval object_zv;
    ZVAL_OBJ(&object_zv, obj);
    return judy_object_read_dimension_helper(&object_zv, offset, rv);
}

int judy_object_write_dimension_helper(zval *object, zval *offset, zval *value) /* {{{ */
{
	zend_long index;
	zval *pstring_key = NULL;
	judy_object *intern = php_judy_object(Z_OBJ_P(object));
	int error_flag = 0;

	if (offset) {
		CHECK_ARRAY_AND_ARG_TYPE(index, pstring_key, error_flag, return FAILURE);
		if (error_flag) {
			return FAILURE;
		}
	} else {
		if (intern->type == TYPE_STRING_TO_INT || intern->type == TYPE_STRING_TO_MIXED) {
			zend_throw_exception(NULL, "Judy STRING_TO_INT and STRING_TO_MIXED values cannot be set without specifying a key", 0);
			return FAILURE;
		}
	}

	if (intern->type == TYPE_BITSET) {
		int         Rc_int;

		if (!offset || index <= -1) {
			if (intern->array) {
				if (!offset && intern->next_empty_is_valid) {
					index = intern->next_empty++;
				} else {
					index = -1;
					J1L(Rc_int, intern->array, index);

					if (Rc_int == 1) {
						index += 1;
						if (!offset) {
							intern->next_empty = index + 1;
							intern->next_empty_is_valid = 1;
						}
					} else {
						return FAILURE;
					}
				}
			} else {
				if (intern->next_empty_is_valid) {
					index = intern->next_empty++;
				} else {
					index = 0;
				}
			}
		} else {
			intern->next_empty_is_valid = 0;
		}

		if (zend_is_true(value)) {
			J1S(Rc_int, intern->array, index);
		} else {
			J1U(Rc_int, intern->array, index);
		}
		return Rc_int ? SUCCESS : FAILURE;
	} else if (intern->type == TYPE_INT_TO_INT) {
		Pvoid_t   *PValue;
		zend_long value_long = zval_get_long(value);

		if (!offset || index <= -1) {
			if (intern->array) {
				if (!offset && intern->next_empty_is_valid) {
					index = intern->next_empty++;
				} else {
					index = -1;
					JLL(PValue, intern->array, index);

					if (PValue != NULL && PValue != PJERR) {
						index += 1;
						if (!offset) {
							intern->next_empty = index + 1;
							intern->next_empty_is_valid = 1;
						}
					} else {
						return FAILURE;
					}
				}
			} else {
				if (intern->next_empty_is_valid) {
					index = intern->next_empty++;
				} else {
					index = 0;
				}
			}
		} else {
			intern->next_empty_is_valid = 0;
		}

		JLI(PValue, intern->array, index);
		if (PValue != NULL && PValue != PJERR) {
			*PValue = (void *)value_long;
			return SUCCESS;
		}
		return FAILURE;
	} else if (intern->type == TYPE_INT_TO_MIXED) {
		Pvoid_t     *PValue;

		if (!offset || index <= -1) {
			if (intern->array){
				if (!offset && intern->next_empty_is_valid) {
					index = intern->next_empty++;
				} else {
					index = -1;
					JLL(PValue, intern->array, index);

					if (PValue != NULL && PValue != PJERR) {
						index += 1;
						if (!offset) {
							intern->next_empty = index + 1;
							intern->next_empty_is_valid = 1;
						}
					} else {
						return FAILURE;
					}
				}
			} else {
				if (intern->next_empty_is_valid) {
					index = intern->next_empty++;
				} else {
					index = 0;
				}
			}
		} else {
			intern->next_empty_is_valid = 0;
		}

		JLI(PValue, intern->array, index);
		if (PValue != NULL && PValue != PJERR) {
			zval *old_value, *new_value;
			if (*PValue != NULL) {
				old_value = (zval *)*PValue;
				zval_ptr_dtor(old_value);
				efree(old_value);
			}
			new_value = ecalloc(1, sizeof(zval));
			ZVAL_COPY(new_value, value);
			*PValue = new_value;
			return SUCCESS;
		}
		return FAILURE;
	} else if (intern->type == TYPE_STRING_TO_INT) {
		PWord_t     *PValue;
		PWord_t     *PExisting;
		int res;

		/* Check if key already exists before insert to track count correctly */
		JSLG(PExisting, intern->array, (uint8_t *)Z_STRVAL_P(pstring_key));

		JSLI(PValue, intern->array, (uint8_t *)Z_STRVAL_P(pstring_key));
		if (PValue != NULL && PValue != PJERR) {
			*PValue = (void *)zval_get_long(value);
			if (PExisting == NULL) {
				intern->counter++;
			}
			res = SUCCESS;
		} else {
			res = FAILURE;
		}
		return res;
	} else if (intern->type == TYPE_STRING_TO_MIXED) {
		Pvoid_t *PValue;
		int res;

		JSLI(PValue, intern->array, (uint8_t *)Z_STRVAL_P(pstring_key));
		if (PValue != NULL && PValue != PJERR) {
			zval *old_value, *new_value;
			if (*PValue != NULL) {
				old_value = (zval *)*PValue;
				zval_ptr_dtor(old_value);
				efree(old_value);
			} else {
				intern->counter++;
			}
			new_value = ecalloc(1, sizeof(zval));
			ZVAL_COPY(new_value, value);
			*PValue = new_value;
			res = SUCCESS;
		} else {
			res = FAILURE;
		}
		return res;
	}
	return FAILURE;
}
/* }}} */

static void judy_object_write_dimension(zend_object *obj, zval *offset, zval *value)
{
    zval object_zv;
    ZVAL_OBJ(&object_zv, obj);
    judy_object_write_dimension_helper(&object_zv, offset, value);
}

int judy_object_has_dimension_helper(zval *object, zval *offset, int check_empty) /* {{{ */
{
	int Rc_int = 0;
	zend_long index = 0;
	Word_t j_index;
	Pvoid_t *PValue = NULL;
	zval *pstring_key = NULL;
	judy_object *intern = php_judy_object(Z_OBJ_P(object));
	int error_flag = 0;

	if (intern->array == NULL) {
		return 0;
	}

	CHECK_ARRAY_AND_ARG_TYPE(index, pstring_key, error_flag, return 0);
	if (error_flag) {
		return 0;
	}

	j_index = index;

	if (intern->type == TYPE_BITSET) {
		int     Rc_int;

		J1T(Rc_int, intern->array, j_index);
		return Rc_int;
	}

	switch(intern->type) {
		case TYPE_INT_TO_INT:
		case TYPE_INT_TO_MIXED:
			JLG(PValue, intern->array, j_index);
			break;
		case TYPE_STRING_TO_INT:
		case TYPE_STRING_TO_MIXED:
			JSLG(PValue, intern->array, (uint8_t *)Z_STRVAL_P(pstring_key));
			break;
	}

	if (PValue != NULL && PValue != PJERR) {
		if (!check_empty) {
			return 1;
		} else if (intern->type == TYPE_INT_TO_INT || intern->type == TYPE_STRING_TO_INT) {
			if (*PValue) {
				return 1;
			}
			return 0;
		} else if (intern->type == TYPE_INT_TO_MIXED || intern->type == TYPE_STRING_TO_MIXED) {
			if (*PValue && zend_is_true((zval *)*PValue)) {
				return 1;
			}
			return 0;
		}
	}
	return 0;
}
/* }}} */

static int judy_object_has_dimension(zend_object *obj, zval *offset, int check_empty)
{
    zval object_zv;
    ZVAL_OBJ(&object_zv, obj);
    return judy_object_has_dimension_helper(&object_zv, offset, check_empty);
}

int judy_object_unset_dimension_helper(zval *object, zval *offset) /* {{{ */
{
	int Rc_int = 0;
	zend_long index = 0;
	Word_t j_index;
	zval *pstring_key = NULL;
	judy_object *intern = php_judy_object(Z_OBJ_P(object));
	int error_flag = 0;

	if (intern->array == NULL) {
		return FAILURE;
	}

	CHECK_ARRAY_AND_ARG_TYPE(index, pstring_key, error_flag, return FAILURE);
	if (error_flag) {
		return FAILURE;
	}

	j_index = index;

	if (intern->type == TYPE_BITSET) {
		J1U(Rc_int, intern->array, j_index);
	} else if (intern->type == TYPE_INT_TO_INT || intern->type == TYPE_INT_TO_MIXED) {
		if (intern->type == TYPE_INT_TO_INT) {
			JLD(Rc_int, intern->array, j_index);
		} else {
			Pvoid_t     *PValue;

			JLG(PValue, intern->array, j_index);
			if (PValue != NULL && PValue != PJERR) {
				zval *value = (zval *)*PValue;
				zval_ptr_dtor(value);
				efree(value);
				JLD(Rc_int, intern->array, j_index);
			}
		}
		if (Rc_int == 1) {
			intern->counter--;
		}
	} else if (intern->type == TYPE_STRING_TO_INT || intern->type == TYPE_STRING_TO_MIXED) {
		if (intern->type == TYPE_STRING_TO_INT) {
			JSLD(Rc_int, intern->array, (uint8_t *)Z_STRVAL_P(pstring_key));
		} else {
			Pvoid_t     *PValue;
			JSLG(PValue, intern->array, (uint8_t *)Z_STRVAL_P(pstring_key));
			if (PValue != NULL && PValue != PJERR) {
				zval *value = (zval *)*PValue;
				zval_ptr_dtor(value);
				efree(value);
				JSLD(Rc_int, intern->array, (uint8_t *)Z_STRVAL_P(pstring_key));
			}
		}
		if (Rc_int == 1) {
			intern->counter--;
		}
	}
	return Rc_int ? SUCCESS : FAILURE;
}
/* }}} */

static void judy_object_unset_dimension(zend_object *obj, zval *offset)
{
    zval object_zv;
    ZVAL_OBJ(&object_zv, obj);
    judy_object_unset_dimension_helper(&object_zv, offset);
}

/* {{{ PHP_MINIT_FUNCTION
*/
PHP_MINIT_FUNCTION(judy)
{
	zend_class_entry ce;

	ZEND_INIT_MODULE_GLOBALS(judy, php_judy_init_globals, NULL);

	REGISTER_INI_ENTRIES();

	/* Judy class definition */

	INIT_CLASS_ENTRY(ce, "Judy", judy_class_methods);

	judy_ce = zend_register_internal_class_ex(&ce, NULL);
	judy_ce->create_object = judy_object_new;

	memcpy(&judy_handlers, zend_get_std_object_handlers(),
			sizeof(zend_object_handlers));

	/* set some internal handlers */
	judy_handlers.clone_obj = judy_object_clone;
	judy_handlers.read_dimension = judy_object_read_dimension;
	judy_handlers.write_dimension = judy_object_write_dimension;
	judy_handlers.unset_dimension = judy_object_unset_dimension;
	judy_handlers.has_dimension = judy_object_has_dimension;
	judy_handlers.dtor_obj = zend_objects_destroy_object;
	judy_handlers.free_obj = judy_object_free_storage;
	judy_handlers.offset = XtOffsetOf(judy_object, std);

	/* implements some interface to provide access to judy object as an array */
	zend_class_implements(judy_ce, 3, zend_ce_arrayaccess, zend_ce_countable, zend_ce_iterator);

	judy_ce->get_iterator = judy_get_iterator;

	REGISTER_STRING_CONSTANT("JUDY_VERSION", PHP_JUDY_VERSION, CONST_PERSISTENT);

	REGISTER_JUDY_CLASS_CONST_LONG("BITSET", TYPE_BITSET);
	REGISTER_JUDY_CLASS_CONST_LONG("INT_TO_INT", TYPE_INT_TO_INT);
	REGISTER_JUDY_CLASS_CONST_LONG("INT_TO_MIXED", TYPE_INT_TO_MIXED);
	REGISTER_JUDY_CLASS_CONST_LONG("STRING_TO_INT", TYPE_STRING_TO_INT);
	REGISTER_JUDY_CLASS_CONST_LONG("STRING_TO_MIXED", TYPE_STRING_TO_MIXED);

	return SUCCESS;
}
/* }}} */

/* {{{ PHP_RINIT_FUNCTION
*/
PHP_RINIT_FUNCTION(judy)
{
	return SUCCESS;
}
/* }}} */

/* {{{ PHP_MSHUTDOWN_FUNCTION
*/
PHP_MSHUTDOWN_FUNCTION(judy)
{
	UNREGISTER_INI_ENTRIES();
	return SUCCESS;
}
/* }}} */

/* {{{ PHP_MINFO_FUNCTION
*/
PHP_MINFO_FUNCTION(judy)
{
	char buf[64];

	php_info_print_table_start();
	php_info_print_table_header(2, "Judy support", "enabled");
	php_info_print_table_row(2, "PHP Judy version", PHP_JUDY_VERSION);
	snprintf(buf, sizeof(buf), "%zu", sizeof(Word_t));
	php_info_print_table_row(2, "sizeof(Word_t)", buf);
	snprintf(buf, sizeof(buf), "%zu", sizeof(Pvoid_t));
	php_info_print_table_row(2, "sizeof(Pvoid_t)", buf);
	php_info_print_table_end();

	DISPLAY_INI_ENTRIES();
}
/* }}} */

/* {{{ proto Judy::__construct(long type)
   Constructs a new Judy array of the given type */
PHP_METHOD(judy, __construct)
{
	zend_long               type;
	judy_type               jtype;

	JUDY_METHOD_GET_OBJECT

	JUDY_METHOD_ERROR_HANDLING;

	if (intern->type) {
		zend_throw_exception(NULL, "Judy Array already instantiated", 0);
	} else if (zend_parse_parameters(ZEND_NUM_ARGS(), "l", &type) == SUCCESS) {
		JTYPE(jtype, type);
		if (jtype == 0) {
			zend_restore_error_handling(&error_handling);
			return;
		}
		intern->counter = 0;
		intern->type = jtype;
		intern->array = (Pvoid_t) NULL;

		/* Initialize cached type flags for performance optimization */
		intern->is_integer_keyed = (jtype == TYPE_BITSET || jtype == TYPE_INT_TO_INT || jtype == TYPE_INT_TO_MIXED);
		intern->is_string_keyed = (jtype == TYPE_STRING_TO_INT || jtype == TYPE_STRING_TO_MIXED);
		intern->is_mixed_value = (jtype == TYPE_INT_TO_MIXED || jtype == TYPE_STRING_TO_MIXED);
	}

	zend_restore_error_handling(&error_handling);
}
/* }}} */

/* {{{ proto Judy::__destruct()
   Free Judy array and any other references */
PHP_METHOD(judy, __destruct)
{
	zval *object = getThis();
	judy_object *intern = php_judy_object(Z_OBJ_P(object));

	/* Clean up iterator state (set UNDEF to prevent double-free in free_obj) */
	zval_ptr_dtor(&intern->iterator_key);
	ZVAL_UNDEF(&intern->iterator_key);
	zval_ptr_dtor(&intern->iterator_data);
	ZVAL_UNDEF(&intern->iterator_data);

	/* calling the object's free() method */
	zend_call_method_with_0_params(Z_OBJ_P(object), NULL, NULL, "free", NULL);
}
/* }}} */

/* {{{ proto long Judy::free()
   Free the entire Judy Array. Return the number of bytes freed */
PHP_METHOD(judy, free)
{
	JUDY_METHOD_GET_OBJECT

		Word_t    Rc_word = 0;
	Word_t    index;
	uint8_t   kindex[PHP_JUDY_MAX_LENGTH];
	Word_t    *PValue;

	switch (intern->type)
	{
		case TYPE_BITSET:
			/* Free Judy1 Array */
			J1FA(Rc_word, intern->array);
			break;

		case TYPE_INT_TO_INT:
			/* Free JudyL Array */
			JLFA(Rc_word, intern->array);
			break;

		case TYPE_INT_TO_MIXED:
			index = 0;

			/* Del ref to zval objects */
			JLF(PValue, intern->array, index);
			while(PValue != NULL && PValue != PJERR)
			{
				zval *value = (zval *)*PValue;
				zval_ptr_dtor(value);
				efree(value);
				JLN(PValue, intern->array, index);
			}

			/* Free JudyL Array */
			JLFA(Rc_word, intern->array);
			break;

		case TYPE_STRING_TO_INT:
			/* Free JudySL Array */
			JSLFA(Rc_word, intern->array);

			/* Reset counter */
			intern->counter = 0;
			break;

		case TYPE_STRING_TO_MIXED:
			kindex[0] = '\0';

			/* Del ref to zval objects */
			JSLF(PValue, intern->array, kindex);
			while(PValue != NULL && PValue != PJERR)
			{
				zval *value = (zval *)*PValue;
				zval_ptr_dtor(value);
				efree(value);
				JSLN(PValue, intern->array, kindex);
			}

			/* Free JudySL Array */
			JSLFA(Rc_word, intern->array);

			/* Reset counter */
			intern->counter = 0;
			break;
	}

	intern->array = NULL;

	RETURN_LONG(Rc_word);
}
/* }}} */

/* {{{ proto long Judy::memoryUsage()
   Return the memory used by the Judy Array */
PHP_METHOD(judy, memoryUsage)
{
	Word_t     Rc_word;

	JUDY_METHOD_GET_OBJECT

		switch (intern->type)
		{
			case TYPE_BITSET:
				J1MU(Rc_word, intern->array);
				RETURN_LONG(Rc_word);
				break;
			case TYPE_INT_TO_INT:
			case TYPE_INT_TO_MIXED:
				JLMU(Rc_word, intern->array);
				RETURN_LONG(Rc_word);
				break;
			default:
				RETURN_NULL();
		}
}
/* }}} */

/* {{{ proto long Judy::size()
   Return the current size of the array. */
PHP_METHOD(judy, size)
{
	JUDY_METHOD_GET_OBJECT

		if (intern->type == TYPE_BITSET || intern->type == TYPE_INT_TO_INT
				|| intern->type == TYPE_INT_TO_MIXED) {
			Word_t   idx1 = 0;
			Word_t   idx2 = -1;
			Word_t   Rc_word;

			if (zend_parse_parameters(ZEND_NUM_ARGS(), "|ll", &idx1, &idx2) == FAILURE) {
				RETURN_FALSE;
			}

			if (intern->type == TYPE_BITSET) {
				J1C(Rc_word, intern->array, idx1, idx2);
			} else {
				JLC(Rc_word, intern->array, idx1, idx2);
			}

			RETURN_LONG(Rc_word);
		} else if (intern->type == TYPE_STRING_TO_INT || intern->type == TYPE_STRING_TO_MIXED) {
			RETURN_LONG(intern->counter);
		}
}

/* {{{ proto long Judy::count()
   Return the current size of the array. */
PHP_METHOD(judy, count)
{
	JUDY_METHOD_GET_OBJECT

		if (intern->type == TYPE_BITSET || intern->type == TYPE_INT_TO_INT
				|| intern->type == TYPE_INT_TO_MIXED) {
			Word_t   idx1 = 0;
			Word_t   idx2 = -1;
			Word_t   Rc_word;

			if (intern->type == TYPE_BITSET) {
				J1C(Rc_word, intern->array, idx1, idx2);
			} else {
				JLC(Rc_word, intern->array, idx1, idx2);
			}

			RETURN_LONG(Rc_word);
		} else if (intern->type == TYPE_STRING_TO_INT || intern->type == TYPE_STRING_TO_MIXED) {
			RETURN_LONG(intern->counter);
		}
}

/* }}} */

/* {{{ proto long Judy::byCount(long nth_index)
   Locate the Nth index that is present in the Judy array (Nth = 1 returns the first index present).
   To refer to the last index in a fully populated array (all indexes present, which is rare), use Nth = 0. */
PHP_METHOD(judy, byCount)
{

	JUDY_METHOD_GET_OBJECT

		if (intern->type == TYPE_BITSET || intern->type == TYPE_INT_TO_INT
				|| intern->type == TYPE_INT_TO_MIXED) {
			zend_long       nth_index;
			Word_t            index;

			if (zend_parse_parameters(ZEND_NUM_ARGS(), "l", &nth_index) == FAILURE) {
				RETURN_FALSE;
			}

			if (intern->type == TYPE_BITSET) {
				int Rc_int;
				J1BC(Rc_int, intern->array, nth_index, index);
				if (Rc_int == 1)
					RETURN_LONG(index);
			} else {
				PWord_t PValue;
				JLBC(PValue, intern->array, nth_index, index);
				if (PValue != NULL && PValue != PJERR)
					RETURN_LONG(index);
			}
		}

	RETURN_NULL();
}
/* }}} */

/* {{{ proto mixed Judy::first([mixed index])
   Search (inclusive) for the first index present that is equal to or greater than the passed Index */
PHP_METHOD(judy, first)
{

	JUDY_METHOD_GET_OBJECT

	if (intern->type == TYPE_BITSET) {
		Word_t          index = 0;
		int             Rc_int;

		if (zend_parse_parameters(ZEND_NUM_ARGS(), "|l", &index) == FAILURE) {
			RETURN_FALSE;
		}

		J1F(Rc_int, intern->array, index);
		if (Rc_int == 1)
			RETURN_LONG(index);
	} else if (intern->type == TYPE_INT_TO_INT || intern->type == TYPE_INT_TO_MIXED) {
		Word_t          index = 0;
		PWord_t         PValue;

		if (zend_parse_parameters(ZEND_NUM_ARGS(), "|l", &index) == FAILURE) {
			RETURN_FALSE;
		}

		JLF(PValue, intern->array, index);
		if (PValue != NULL && PValue != PJERR)
			RETURN_LONG(index);
	} else if (intern->type == TYPE_STRING_TO_INT || intern->type == TYPE_STRING_TO_MIXED) {
		char        *str;
		size_t       str_length = 0;

		uint8_t     key[PHP_JUDY_MAX_LENGTH];
		PWord_t     PValue;

		if (zend_parse_parameters(ZEND_NUM_ARGS(), "|s", &str, &str_length) == FAILURE) {
			RETURN_FALSE;
		}

		/* JudySL require null terminated strings */
		if (str_length == 0) {
			key[0] = '\0';
		} else {
			int key_len = str_length >= PHP_JUDY_MAX_LENGTH ? PHP_JUDY_MAX_LENGTH - 1 : str_length;
			memcpy(key, str, key_len);
			key[key_len] = '\0';
		}

		JSLF(PValue, intern->array, key);
		if (PValue != NULL && PValue != PJERR)
			RETURN_STRING((char *)key);
	}

	RETURN_NULL();
}
/* }}} */

/* {{{ proto mixed Judy::searchNext(mixed index)
   Search (exclusive) for the next index present that is greater than the passed Index
   
   This method was renamed from next() to avoid Iterator interface conflicts.
   The original search functionality has been moved to searchNext() method.
   Original functionality: Search (exclusive) for the next index present
   that is greater than the passed Index.
   */
PHP_METHOD(judy, searchNext)
{

	JUDY_METHOD_GET_OBJECT

	if (intern->type == TYPE_BITSET) {
		Word_t          index;
		int             Rc_int;

		if (zend_parse_parameters(ZEND_NUM_ARGS(), "l", &index) == FAILURE) {
			RETURN_FALSE;
		}

		J1N(Rc_int, intern->array, index);
		if (Rc_int == 1)
			RETURN_LONG(index);
	} else if (intern->type == TYPE_INT_TO_INT || intern->type == TYPE_INT_TO_MIXED) {
		Word_t          index;
		PWord_t         PValue;

		if (zend_parse_parameters(ZEND_NUM_ARGS(), "l", &index) == FAILURE) {
			RETURN_FALSE;
		}

		JLN(PValue, intern->array, index);
		if (PValue != NULL && PValue != PJERR)
			RETURN_LONG(index);
	} else if (intern->type == TYPE_STRING_TO_INT || intern->type == TYPE_STRING_TO_MIXED) {
		char        *str;
		size_t       str_length;

		uint8_t     key[PHP_JUDY_MAX_LENGTH];
		PWord_t     PValue;

		if (zend_parse_parameters(ZEND_NUM_ARGS(), "s", &str, &str_length) == FAILURE) {
			RETURN_FALSE;
		}

		/* JudySL require null terminated strings */
		if (str_length == 0) {
			key[0] = '\0';
		} else {
			int key_len = str_length >= PHP_JUDY_MAX_LENGTH ? PHP_JUDY_MAX_LENGTH - 1 : str_length;
			memcpy(key, str, key_len);
			key[key_len] = '\0';
		}

		JSLN(PValue, intern->array, key);
		if (PValue != NULL && PValue != PJERR)
			RETURN_STRING((char *)key);
	}

	RETURN_NULL();
}
/* }}} */

/* {{{ Iterator interface next() method - Fixes GitHub issue #25
 *
 * This method was separated from the original Judy::next() search function
 * to resolve naming conflicts with the Iterator interface. The original
 * search functionality has been moved to searchNext() method.
 *
 * This zero-argument method is required by the Iterator interface and
 * is called by foreach loops to advance the iterator position.
 */
PHP_METHOD(judy, next)
{
	JUDY_METHOD_GET_OBJECT
	
	/* Clear current data */
	zval_ptr_dtor(&intern->iterator_data);
	ZVAL_UNDEF(&intern->iterator_data);

	/* Only advance the iterator if it is currently valid */
	if (!intern->iterator_initialized || Z_ISUNDEF_P(&intern->iterator_key)) {
		/* Iterator is invalid, do nothing */
		return;
	}

	if (intern->type == TYPE_BITSET) {
		Word_t          index = Z_LVAL_P(&intern->iterator_key);
		int             Rc_int;

		J1N(Rc_int, intern->array, index);

		if (Rc_int) {
			zval_ptr_dtor(&intern->iterator_key);
			ZVAL_LONG(&intern->iterator_key, index);
			ZVAL_BOOL(&intern->iterator_data, 1);
			intern->iterator_initialized = 1;
		} else {
			ZVAL_UNDEF(&intern->iterator_key);
			intern->iterator_initialized = 0;
		}

	} else if (JUDY_IS_INTEGER_KEYED(intern)) {
		Word_t          index = Z_LVAL_P(&intern->iterator_key);
		Pvoid_t          *PValue = NULL;

		JLN(PValue, intern->array, index);

		if (PValue != NULL && PValue != PJERR) {
			zval_ptr_dtor(&intern->iterator_key);
			ZVAL_LONG(&intern->iterator_key, index);

			if (intern->type == TYPE_INT_TO_INT) {
				ZVAL_LONG(&intern->iterator_data, (zend_long)*PValue);
			} else {
				zval *value = *(zval **)PValue;
				ZVAL_COPY(&intern->iterator_data, value);
			}
			intern->iterator_initialized = 1;
		} else {
			ZVAL_UNDEF(&intern->iterator_key);
			ZVAL_UNDEF(&intern->iterator_data);
			intern->iterator_initialized = 0;
		}

	} else if (JUDY_IS_STRING_KEYED(intern)) {
		uint8_t     key[PHP_JUDY_MAX_LENGTH];
		Pvoid_t      *PValue;

		if (Z_TYPE_P(&intern->iterator_key) == IS_STRING) {
			int key_len;
			key_len = Z_STRLEN_P(&intern->iterator_key) >= PHP_JUDY_MAX_LENGTH ? PHP_JUDY_MAX_LENGTH - 1 : Z_STRLEN_P(&intern->iterator_key);
			memcpy(key, Z_STRVAL_P(&intern->iterator_key), key_len);
			key[key_len] = '\0';
			JSLN(PValue, intern->array, key);
		} else {
			/* Invalid key type, mark iterator as invalid */
			ZVAL_UNDEF(&intern->iterator_key);
			intern->iterator_initialized = 0;
			return;
		}

		if (PValue != NULL && PValue != PJERR) {
			zval_ptr_dtor(&intern->iterator_key);
			ZVAL_STRING(&intern->iterator_key, (char *)key);

			if (JUDY_IS_MIXED_VALUE(intern)) {
				zval *value = *(zval **)PValue;
				ZVAL_COPY(&intern->iterator_data, value);
			} else {
				ZVAL_LONG(&intern->iterator_data, (zend_long)*PValue);
			}
			intern->iterator_initialized = 1;
		} else {
			ZVAL_UNDEF(&intern->iterator_key);
			intern->iterator_initialized = 0;
		}
	}
}
/* }}} */

/* {{{ Iterator interface rewind() method - Fixes GitHub issue #25 */
PHP_METHOD(judy, rewind)
{
	JUDY_METHOD_GET_OBJECT
	
	/* Clear current data */
	zval_ptr_dtor(&intern->iterator_data);
	ZVAL_UNDEF(&intern->iterator_data);

	if (intern->type == TYPE_BITSET) {
		Word_t          index = 0;
		int             Rc_int;

		J1F(Rc_int, intern->array, index);
		if (Rc_int) {
			zval_ptr_dtor(&intern->iterator_key);
			ZVAL_LONG(&intern->iterator_key, index);
			ZVAL_BOOL(&intern->iterator_data, 1);
			intern->iterator_initialized = 1;
		} else {
			/* Array is empty, mark iterator as invalid */
			ZVAL_UNDEF(&intern->iterator_key);
			ZVAL_UNDEF(&intern->iterator_data);
			intern->iterator_initialized = 0;
		}

	} else if (JUDY_IS_INTEGER_KEYED(intern)) {
		Word_t          index = 0;
		Pvoid_t          *PValue = NULL;

		JLF(PValue, intern->array, index);
		if (PValue != NULL && PValue != PJERR) {
			zval_ptr_dtor(&intern->iterator_key);
			ZVAL_LONG(&intern->iterator_key, index);

			if (JUDY_IS_MIXED_VALUE(intern)) {
				zval *value = *(zval **)PValue;
				ZVAL_COPY(&intern->iterator_data, value);
			} else {
				ZVAL_LONG(&intern->iterator_data, (zend_long)*PValue);
			}
			intern->iterator_initialized = 1;
		} else {
			ZVAL_UNDEF(&intern->iterator_key);
			ZVAL_UNDEF(&intern->iterator_data);
			intern->iterator_initialized = 0;
		}

	} else if (JUDY_IS_STRING_KEYED(intern)) {
		uint8_t     key[PHP_JUDY_MAX_LENGTH];
		Pvoid_t      *PValue;

		key[0] = '\0';
		JSLF(PValue, intern->array, key);

		if (PValue != NULL && PValue != PJERR) {
			zval_ptr_dtor(&intern->iterator_key);
			ZVAL_STRING(&intern->iterator_key, (const char *) key);
			if (JUDY_IS_MIXED_VALUE(intern)) {
				zval *value = *(zval **)PValue;
				ZVAL_COPY(&intern->iterator_data, value);
			} else {
				ZVAL_LONG(&intern->iterator_data, (zend_long)*PValue);
			}
			intern->iterator_initialized = 1;
		} else {
			ZVAL_UNDEF(&intern->iterator_key);
			ZVAL_UNDEF(&intern->iterator_data);
			intern->iterator_initialized = 0;
		}
	}
}
/* }}} */

/* {{{ Iterator interface valid() method - Fixes GitHub issue #25 */
PHP_METHOD(judy, valid)
{
	JUDY_METHOD_GET_OBJECT
	
	/*
	 * The iterator is valid if it has been initialized by rewind() or next()
	 * and has not reached the end of the array. The iterator_initialized flag
	 * tracks this state. This avoids performing a slow lookup on every
	 * iteration of a foreach loop.
	 */
	RETURN_BOOL(intern->iterator_initialized);
}
/* }}} */

/* {{{ Iterator interface current() method - Fixes GitHub issue #25 */
PHP_METHOD(judy, current)
{
	JUDY_METHOD_GET_OBJECT
	
	if (!intern->iterator_initialized || Z_ISUNDEF_P(&intern->iterator_data)) {
		RETURN_NULL();
	}

	ZVAL_COPY(return_value, &intern->iterator_data);
}
/* }}} */

/* {{{ Iterator interface key() method - Fixes GitHub issue #25 */
PHP_METHOD(judy, key)
{
	JUDY_METHOD_GET_OBJECT
	
	if (!intern->iterator_initialized || Z_ISUNDEF_P(&intern->iterator_key)) {
		RETURN_NULL();
	}

	ZVAL_COPY(return_value, &intern->iterator_key);
}
/* }}} */

/* {{{ proto mixed Judy::last([mixed index])
   Search (inclusive) for the last index present that is equal to or less than the passed Index */
PHP_METHOD(judy, last)
{

	JUDY_METHOD_GET_OBJECT

	if (intern->type == TYPE_BITSET) {
		Word_t       index = -1;
		int          Rc_int;

		if (zend_parse_parameters(ZEND_NUM_ARGS(), "|l", &index) == FAILURE) {
			RETURN_FALSE;
		}

		J1L(Rc_int, intern->array, index);
		if (Rc_int == 1)
			RETURN_LONG(index);
	} else if (intern->type == TYPE_INT_TO_INT || intern->type == TYPE_INT_TO_MIXED) {
		Word_t          index = -1;
		PWord_t         PValue;

		if (zend_parse_parameters(ZEND_NUM_ARGS(), "|l", &index) == FAILURE) {
			RETURN_FALSE;
		}

		JLL(PValue, intern->array, index);
		if (PValue != NULL && PValue != PJERR)
			RETURN_LONG(index);
	} else if (intern->type == TYPE_STRING_TO_INT || intern->type == TYPE_STRING_TO_MIXED) {
		uint8_t     *str;
		size_t       str_length = 0;

		uint8_t     key[PHP_JUDY_MAX_LENGTH];
		PWord_t     PValue;

		if (zend_parse_parameters(ZEND_NUM_ARGS(), "|s", &str, &str_length) == FAILURE) {
			RETURN_FALSE;
		}

		/* JudySL require null terminated strings */
		if (str_length == 0) {
			memset(key, 0xff, PHP_JUDY_MAX_LENGTH);
			key[PHP_JUDY_MAX_LENGTH-1] = '\0';
		} else {
			int key_len = str_length >= PHP_JUDY_MAX_LENGTH ? PHP_JUDY_MAX_LENGTH - 1 : str_length;
			memcpy(key, str, key_len);
			key[key_len] = '\0';
		}

		JSLL(PValue, intern->array, key);
		if (PValue != NULL && PValue != PJERR)
			RETURN_STRING((char *)key);
	}

	RETURN_NULL();
}
/* }}} */

/* {{{ proto mixed Judy::prev(mixed index)
   Search (exclusive) for the previous index present that is less than the passed Index */
PHP_METHOD(judy, prev)
{

	JUDY_METHOD_GET_OBJECT

	if (intern->type == TYPE_BITSET) {
		Word_t       index;
		int          Rc_int;

		if (zend_parse_parameters(ZEND_NUM_ARGS(), "l", &index) == FAILURE) {
			RETURN_FALSE;
		}

		J1P(Rc_int, intern->array, index);
		if (Rc_int == 1)
			RETURN_LONG(index);
	} else if (intern->type == TYPE_INT_TO_INT || intern->type == TYPE_INT_TO_MIXED) {
		Word_t          index;
		PWord_t         PValue;

		if (zend_parse_parameters(ZEND_NUM_ARGS(), "l", &index) == FAILURE) {
			RETURN_FALSE;
		}

		JLP(PValue, intern->array, index);
		if (PValue != NULL && PValue != PJERR)
			RETURN_LONG(index);
	} else if (intern->type == TYPE_STRING_TO_INT || intern->type == TYPE_STRING_TO_MIXED) {
		char        *str;
		size_t       str_length;

		uint8_t     key[PHP_JUDY_MAX_LENGTH];
		PWord_t     PValue;

		if (zend_parse_parameters(ZEND_NUM_ARGS(), "s", &str, &str_length) == FAILURE) {
			RETURN_FALSE;
		}

		/* JudySL require null terminated strings */
		if (str_length == 0) {
			key[0] = '\0';
		} else {
			int key_len = str_length >= PHP_JUDY_MAX_LENGTH ? PHP_JUDY_MAX_LENGTH - 1 : str_length;
			memcpy(key, str, key_len);
			key[key_len] = '\0';
		}

		JSLP(PValue, intern->array, key);
		if (PValue != NULL && PValue != PJERR)
			RETURN_STRING((char *)key);
	}

	RETURN_NULL();
}
/* }}} */

/* {{{ proto long Judy::firstEmpty([long index])
   Search (inclusive) for the first absent index that is equal to or greater than the passed Index */
PHP_METHOD(judy, firstEmpty)
{
	Word_t         index = 0;
	int            Rc_int = 0;

	JUDY_METHOD_GET_OBJECT

	if (zend_parse_parameters(ZEND_NUM_ARGS(), "|l", &index) == FAILURE) {
		RETURN_FALSE;
	}

	switch (intern->type)
	{
		case TYPE_BITSET:
			J1FE(Rc_int, intern->array, index);
			break;
		case TYPE_INT_TO_INT:
		case TYPE_INT_TO_MIXED:
			JLFE(Rc_int, intern->array, index);
			break;
	}

	if (Rc_int == 1) {
		RETURN_LONG(index);
	} else {
		RETURN_NULL();
	}
}
/* }}} */

/* {{{ proto long Judy::lastEmpty([long index])
   Search (inclusive) for the last absent index that is equal to or less than the passed Index */
PHP_METHOD(judy, lastEmpty)
{
	Word_t         index = -1;
	int            Rc_int = 0;

	JUDY_METHOD_GET_OBJECT

	if (zend_parse_parameters(ZEND_NUM_ARGS(), "|l", &index) == FAILURE) {
		RETURN_FALSE;
	}

	switch (intern->type)
	{
		case TYPE_BITSET:
			J1LE(Rc_int, intern->array, index);
			break;
		case TYPE_INT_TO_INT:
		case TYPE_INT_TO_MIXED:
			JLLE(Rc_int, intern->array, index);
			break;
	}

	if (Rc_int == 1) {
		RETURN_LONG(index);
	} else {
		RETURN_NULL();
	}
}
/* }}} */

/* {{{ proto long Judy::nextEmpty(long index)
   Search (exclusive) for the next absent index that is greater than the passed Index */
PHP_METHOD(judy, nextEmpty)
{
	Word_t         index;
	int            Rc_int = 0;

	JUDY_METHOD_GET_OBJECT

	if (zend_parse_parameters(ZEND_NUM_ARGS(), "l", &index) == FAILURE) {
		RETURN_FALSE;
	}

	switch (intern->type)
	{
		case TYPE_BITSET:
			J1NE(Rc_int, intern->array, index);
			break;
		case TYPE_INT_TO_INT:
		case TYPE_INT_TO_MIXED:
			JLNE(Rc_int, intern->array, index);
			break;
	}

	if (Rc_int == 1) {
		RETURN_LONG(index);
	} else {
		RETURN_NULL();
	}
}
/* }}} */

/* {{{ proto long Judy::prevEmpty(long index)
   Search (exclusive) for the previous index absent that is less than the passed Index */
PHP_METHOD(judy, prevEmpty)
{
	Word_t         index;
	int            Rc_int = 0;

	JUDY_METHOD_GET_OBJECT

	if (zend_parse_parameters(ZEND_NUM_ARGS(), "l", &index) == FAILURE) {
		RETURN_FALSE;
	}

	switch (intern->type)
	{
		case TYPE_BITSET:
			J1PE(Rc_int, intern->array, index);
			break;
		case TYPE_INT_TO_INT:
		case TYPE_INT_TO_MIXED:
			JLPE(Rc_int, intern->array, index);
			break;
	}

	if (Rc_int == 1) {
		RETURN_LONG(index);
	} else {
		RETURN_NULL();
	}
}
/* }}} */

/* {{{ proto int Judy::getType()
   Return the current Judy Array type */
PHP_METHOD(judy, getType)
{
	JUDY_METHOD_GET_OBJECT
	RETURN_LONG(intern->type);
}
/* }}} */

/* {{{ proto string judy_version()
   Return the php judy version */
PHP_FUNCTION(judy_version)
{
	RETURN_STRING(PHP_JUDY_VERSION);
}
/* }}} */

/* {{{ proto int judy_type(Judy array)
   Return the php judy type for the given array */
PHP_FUNCTION(judy_type)
{
	zval *object;
	judy_object *array;

	if (zend_parse_parameters(ZEND_NUM_ARGS(), "O", &object, judy_ce) == FAILURE) {
		RETURN_FALSE;
	}

	array = php_judy_object(Z_OBJ_P(object));
	RETURN_LONG(array->type);
}
/* }}} */

PHP_MINIT_FUNCTION(judy);
PHP_MSHUTDOWN_FUNCTION(judy);
PHP_RINIT_FUNCTION(judy);
PHP_MINFO_FUNCTION(judy);

/* PHP Judy Function */
PHP_FUNCTION(judy_version);
PHP_FUNCTION(judy_type);

/* {{{ PHP Judy Methods
*/
PHP_METHOD(judy, __construct);
PHP_METHOD(judy, __destruct);
PHP_METHOD(judy, getType);
PHP_METHOD(judy, free);
PHP_METHOD(judy, memoryUsage);
PHP_METHOD(judy, count);
PHP_METHOD(judy, byCount);
PHP_METHOD(judy, first);
PHP_METHOD(judy, next);
PHP_METHOD(judy, last);
PHP_METHOD(judy, prev);
PHP_METHOD(judy, firstEmpty);
PHP_METHOD(judy, nextEmpty);
PHP_METHOD(judy, lastEmpty);
PHP_METHOD(judy, prevEmpty);
/* }}} */

/* {{{ PHP Judy Methods for the Array Access Interface
*/
PHP_METHOD(judy, offsetSet);
PHP_METHOD(judy, offsetUnset);
PHP_METHOD(judy, offsetGet);
PHP_METHOD(judy, offsetExists);
/* }}} */

/* {{{ Judy function parameters
*/
ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_type, 0, 0, 1)
	ZEND_ARG_INFO(0, array)
ZEND_END_ARG_INFO()
/* }}} */

/* {{{ Judy class methods parameters
*/
ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_size, 0, 0, 0)
	ZEND_ARG_INFO(0, index_start)
	ZEND_ARG_INFO(0, index_end)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_byCount, 0, 0, 1)
	ZEND_ARG_INFO(0, nth_index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_first, 0, 0, 1)
	ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_search_next, 0, 0, 1)
	ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_last, 0, 0, 1)
	ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_prev, 0, 0, 1)
	ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_firstEmpty, 0, 0, 1)
	ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_nextEmpty, 0, 0, 1)
	ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_lastEmpty, 0, 0, 1)
	ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy_prevEmpty, 0, 0, 1)
	ZEND_ARG_INFO(0, index)
ZEND_END_ARG_INFO()
/* }}} */

/* {{{ Judy class methods parameters the Array Access Interface
*/
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_judy_offsetExists, 0, 1, _IS_BOOL, 0)
	ZEND_ARG_TYPE_INFO(0, offset, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_judy_offsetGet, 0, 1, IS_MIXED, 0)
	ZEND_ARG_TYPE_INFO(0, offset, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_judy_offsetSet, 0, 2, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, offset, IS_MIXED, 0)
	ZEND_ARG_TYPE_INFO(0, value, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_judy_offsetUnset, 0, 1, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, offset, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_judy_count, 0, 0, IS_LONG, 0)
ZEND_END_ARG_INFO()

/* }}} */

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_judy_version, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy___construct, 0, 0, 1)
	ZEND_ARG_TYPE_INFO(0, type, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_judy___destruct, 0, 0, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_judy_getType, 0, 0, IS_LONG, 0)
ZEND_END_ARG_INFO()

#define arginfo_judy_free arginfo_judy_getType

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_judy_memoryUsage, 0, 0, IS_LONG, 1)
ZEND_END_ARG_INFO()

/* Iterator interface methods - Fixes GitHub issue #25 */
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_judy_rewind, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_judy_valid, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_judy_current, 0, 0, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_judy_key, 0, 0, IS_MIXED, 0)
ZEND_END_ARG_INFO()

/* Iterator interface next() method signature */
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_judy_next, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

/* {{{ judy_functions[]
 *
 * Every user visible function must have an entry in judy_functions[].
 */
const zend_function_entry judy_functions[] = {
	/* PHP JUDY FUNCTIONS */
	PHP_FE(judy_version, arginfo_judy_version)
	PHP_FE(judy_type, arginfo_judy_type)
	{NULL, NULL, NULL}
};
/* }}} */

/* {{{ judy_class_methodss[]
 *
 * Every user visible Judy method must have an entry in judy_class_methods[].
 */
const zend_function_entry judy_class_methods[] = {
	/* PHP JUDY METHODS */
	PHP_ME(judy, __construct, 		arginfo_judy___construct, ZEND_ACC_PUBLIC)
	PHP_ME(judy, __destruct, 		arginfo_judy___destruct, ZEND_ACC_PUBLIC)
	PHP_ME(judy, getType, 			arginfo_judy_getType, ZEND_ACC_PUBLIC)
	PHP_ME(judy, free, 				arginfo_judy_free, ZEND_ACC_PUBLIC)
	PHP_ME(judy, memoryUsage, 		arginfo_judy_memoryUsage, ZEND_ACC_PUBLIC)
	PHP_ME(judy, size, 				arginfo_judy_size, ZEND_ACC_PUBLIC)
	PHP_ME(judy, count, 			arginfo_judy_count, ZEND_ACC_PUBLIC)
	PHP_ME(judy, byCount, 			arginfo_judy_byCount, ZEND_ACC_PUBLIC)
	PHP_ME(judy, first, 			arginfo_judy_first, ZEND_ACC_PUBLIC)
	PHP_ME(judy, searchNext, 		arginfo_judy_search_next, ZEND_ACC_PUBLIC)
	PHP_ME(judy, last, 				arginfo_judy_last, ZEND_ACC_PUBLIC)
	PHP_ME(judy, prev, 				arginfo_judy_prev, ZEND_ACC_PUBLIC)
	PHP_ME(judy, firstEmpty, 		arginfo_judy_firstEmpty, ZEND_ACC_PUBLIC)
	PHP_ME(judy, nextEmpty, 		arginfo_judy_nextEmpty, ZEND_ACC_PUBLIC)
	PHP_ME(judy, lastEmpty, 		arginfo_judy_lastEmpty, ZEND_ACC_PUBLIC)
	PHP_ME(judy, prevEmpty, 		arginfo_judy_prevEmpty, ZEND_ACC_PUBLIC)

	/* PHP JUDY METHODS / ARRAYACCESS INTERFACE */
	PHP_ME(judy, offsetSet, 		arginfo_judy_offsetSet, ZEND_ACC_PUBLIC)
	PHP_ME(judy, offsetUnset, 		arginfo_judy_offsetUnset, ZEND_ACC_PUBLIC)
	PHP_ME(judy, offsetGet, 		arginfo_judy_offsetGet, ZEND_ACC_PUBLIC)
	PHP_ME(judy, offsetExists, 		arginfo_judy_offsetExists, ZEND_ACC_PUBLIC)

	/* Iterator interface methods - Fixes GitHub issue #25 */
	PHP_ME(judy, rewind, 			arginfo_judy_rewind, ZEND_ACC_PUBLIC)
	PHP_ME(judy, valid, 			arginfo_judy_valid, ZEND_ACC_PUBLIC)
	PHP_ME(judy, current, 			arginfo_judy_current, ZEND_ACC_PUBLIC)
	PHP_ME(judy, key, 				arginfo_judy_key, ZEND_ACC_PUBLIC)
	PHP_ME(judy, next, 				arginfo_judy_next, ZEND_ACC_PUBLIC)

	/* NULL TERMINATED VECTOR */
	{NULL, NULL, NULL}
};
/* }}} */

static const zend_module_dep judy_deps[] = {
	ZEND_MOD_REQUIRED("spl")
	{NULL, NULL, NULL}
};

/* {{{ judy_module_entry
*/
zend_module_entry judy_module_entry = {
	STANDARD_MODULE_HEADER_EX, NULL,
	judy_deps,
	PHP_JUDY_EXTNAME,
	judy_functions,
	PHP_MINIT(judy),
	PHP_MSHUTDOWN(judy),
	PHP_RINIT(judy),
	NULL,
	PHP_MINFO(judy),
	PHP_JUDY_VERSION,
	STANDARD_MODULE_PROPERTIES
};
/* }}} */

#ifdef COMPILE_DL_JUDY
ZEND_GET_MODULE(judy)
#endif

/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * End:
 * vim600: noet sw=4 ts=4 fdm=marker
 * vim<600: noet sw=4 ts=4
 */

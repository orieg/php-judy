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
#include "ext/json/php_json.h"
#include "ext/standard/php_var.h"

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
static Word_t judy_free_array_internal(judy_object *intern)
{
	Word_t Rc_word = 0;

	if (intern->array == NULL) {
		return 0;
	}

	if (intern->type == TYPE_INT_TO_MIXED) {
		Word_t index = 0;
		Word_t *PValue;

		JLF(PValue, intern->array, index);
		while (PValue != NULL && PValue != PJERR) {
			zval *value = JUDY_MVAL_READ(PValue);
			zval_ptr_dtor(value);
			efree(value);
			JLN(PValue, intern->array, index);
		}
		JLFA(Rc_word, intern->array);
	} else if (intern->type == TYPE_INT_TO_PACKED) {
		Word_t index = 0;
		Word_t *PValue;

		JLF(PValue, intern->array, index);
		while (PValue != NULL && PValue != PJERR) {
			judy_packed_value *packed = JUDY_PVAL_READ(PValue);
			if (packed) {
				efree(packed);
			}
			JLN(PValue, intern->array, index);
		}
		JLFA(Rc_word, intern->array);
	} else if (intern->type == TYPE_STRING_TO_MIXED) {
		uint8_t kindex[PHP_JUDY_MAX_LENGTH];
		Word_t *PValue;

		kindex[0] = '\0';
		JSLF(PValue, intern->array, kindex);
		while (PValue != NULL && PValue != PJERR) {
			zval *value = JUDY_MVAL_READ(PValue);
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
	intern->counter = 0;

	return Rc_word;
}

/* {{{ judy_pack_value — serialize a zval into a heap-allocated judy_packed_value.
   Returns NULL on serialization failure. Caller owns the returned memory. */
judy_packed_value *judy_pack_value(zval *value)
{
	smart_str buf = {0};
	php_serialize_data_t var_hash;

	PHP_VAR_SERIALIZE_INIT(var_hash);
	php_var_serialize(&buf, value, &var_hash);
	PHP_VAR_SERIALIZE_DESTROY(var_hash);

	if (EG(exception) || !buf.s) {
		smart_str_free(&buf);
		return NULL;
	}

	size_t len = ZSTR_LEN(buf.s);
	judy_packed_value *packed = emalloc(sizeof(judy_packed_value) + len);
	packed->len = len;
	memcpy(packed->data, ZSTR_VAL(buf.s), len);

	smart_str_free(&buf);
	return packed;
}
/* }}} */

/* {{{ judy_unpack_value — unserialize a judy_packed_value into rv.
   Returns SUCCESS/FAILURE. On success, rv holds the reconstructed value. */
int judy_unpack_value(judy_packed_value *packed, zval *rv)
{
	php_unserialize_data_t var_hash;
	const unsigned char *p = (const unsigned char *)packed->data;
	const unsigned char *end = p + packed->len;

	PHP_VAR_UNSERIALIZE_INIT(var_hash);
	int result = php_var_unserialize(rv, &p, end, &var_hash);
	PHP_VAR_UNSERIALIZE_DESTROY(var_hash);

	if (!result) {
		zval_ptr_dtor(rv);
		ZVAL_NULL(rv);
		return FAILURE;
	}
	return SUCCESS;
}
/* }}} */

static void judy_object_free_storage(zend_object *object)
{
	judy_object *intern = php_judy_object(object);

	/* Clean up iterator state */
	zval_ptr_dtor(&intern->iterator_key);
	zval_ptr_dtor(&intern->iterator_data);

	/* Free the Judy array if __destruct didn't already */
	judy_free_array_internal(intern);

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
		case TYPE_INT_TO_PACKED:			\
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
		case TYPE_INT_TO_PACKED:
			JLG(PValue, intern->array, j_index);
			break;
		case TYPE_STRING_TO_INT:
		case TYPE_STRING_TO_MIXED:
			JSLG(PValue, intern->array, (uint8_t *)Z_STRVAL_P(pstring_key));
			break;
	}

	if (PValue != NULL && PValue != PJERR) {
		if (intern->type == TYPE_INT_TO_INT || intern->type == TYPE_STRING_TO_INT) {
			ZVAL_LONG(rv, JUDY_LVAL_READ(PValue));
		} else if (intern->type == TYPE_INT_TO_MIXED || intern->type == TYPE_STRING_TO_MIXED) {
			ZVAL_COPY(rv, JUDY_MVAL_READ(PValue));
		} else if (intern->type == TYPE_INT_TO_PACKED) {
			judy_packed_value *packed = JUDY_PVAL_READ(PValue);
			if (packed) {
				judy_unpack_value(packed, rv);
			} else {
				ZVAL_NULL(rv);
			}
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
		if (pstring_key && Z_STRLEN_P(pstring_key) >= PHP_JUDY_MAX_LENGTH) {
			zend_throw_exception_ex(NULL, 0,
				"Judy string key length (%zu) exceeds maximum of %d bytes",
				(size_t)Z_STRLEN_P(pstring_key), PHP_JUDY_MAX_LENGTH - 1);
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
			JUDY_LVAL_WRITE(PValue, value_long);
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
			if (JUDY_MVAL_READ(PValue) != NULL) {
				old_value = JUDY_MVAL_READ(PValue);
				zval_ptr_dtor(old_value);
				efree(old_value);
			}
			new_value = ecalloc(1, sizeof(zval));
			ZVAL_COPY(new_value, value);
			JUDY_MVAL_WRITE(PValue, new_value);
			return SUCCESS;
		}
		return FAILURE;
	} else if (intern->type == TYPE_INT_TO_PACKED) {
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

		judy_packed_value *packed = judy_pack_value(value);
		if (!packed) {
			return FAILURE;
		}

		JLI(PValue, intern->array, index);
		if (PValue != NULL && PValue != PJERR) {
			judy_packed_value *old = JUDY_PVAL_READ(PValue);
			if (old != NULL) {
				efree(old);
			}
			JUDY_PVAL_WRITE(PValue, packed);
			return SUCCESS;
		}
		efree(packed);
		return FAILURE;
	} else if (intern->type == TYPE_STRING_TO_INT) {
		PWord_t     *PValue;
		PWord_t     *PExisting;
		int res;

		/* Check if key already exists before insert to track count correctly */
		JSLG(PExisting, intern->array, (uint8_t *)Z_STRVAL_P(pstring_key));

		JSLI(PValue, intern->array, (uint8_t *)Z_STRVAL_P(pstring_key));
		if (PValue != NULL && PValue != PJERR) {
			JUDY_LVAL_WRITE(PValue, zval_get_long(value));
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
			if (JUDY_MVAL_READ(PValue) != NULL) {
				old_value = JUDY_MVAL_READ(PValue);
				zval_ptr_dtor(old_value);
				efree(old_value);
			} else {
				intern->counter++;
			}
			new_value = ecalloc(1, sizeof(zval));
			ZVAL_COPY(new_value, value);
			JUDY_MVAL_WRITE(PValue, new_value);
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
		case TYPE_INT_TO_PACKED:
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
			if (JUDY_LVAL_READ(PValue)) {
				return 1;
			}
			return 0;
		} else if (intern->type == TYPE_INT_TO_MIXED || intern->type == TYPE_STRING_TO_MIXED) {
			if (JUDY_MVAL_READ(PValue) && zend_is_true(JUDY_MVAL_READ(PValue))) {
				return 1;
			}
			return 0;
		} else if (intern->type == TYPE_INT_TO_PACKED) {
			judy_packed_value *packed = JUDY_PVAL_READ(PValue);
			if (packed) {
				zval tmp;
				ZVAL_UNDEF(&tmp);
				judy_unpack_value(packed, &tmp);
				int result = zend_is_true(&tmp);
				zval_ptr_dtor(&tmp);
				return result;
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
	} else if (intern->type == TYPE_INT_TO_INT || intern->type == TYPE_INT_TO_MIXED
			|| intern->type == TYPE_INT_TO_PACKED) {
		if (intern->type == TYPE_INT_TO_INT) {
			JLD(Rc_int, intern->array, j_index);
		} else if (intern->type == TYPE_INT_TO_PACKED) {
			Pvoid_t     *PValue;

			JLG(PValue, intern->array, j_index);
			if (PValue != NULL && PValue != PJERR) {
				judy_packed_value *packed = JUDY_PVAL_READ(PValue);
				if (packed) {
					efree(packed);
				}
				JLD(Rc_int, intern->array, j_index);
			}
		} else {
			Pvoid_t     *PValue;

			JLG(PValue, intern->array, j_index);
			if (PValue != NULL && PValue != PJERR) {
				zval *value = JUDY_MVAL_READ(PValue);
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
				zval *value = JUDY_MVAL_READ(PValue);
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
	zend_class_implements(judy_ce, 4, zend_ce_arrayaccess, zend_ce_countable, zend_ce_iterator, php_json_serializable_ce);

	judy_ce->get_iterator = judy_get_iterator;

	REGISTER_STRING_CONSTANT("JUDY_VERSION", PHP_JUDY_VERSION, CONST_PERSISTENT);

	REGISTER_JUDY_CLASS_CONST_LONG("BITSET", TYPE_BITSET);
	REGISTER_JUDY_CLASS_CONST_LONG("INT_TO_INT", TYPE_INT_TO_INT);
	REGISTER_JUDY_CLASS_CONST_LONG("INT_TO_MIXED", TYPE_INT_TO_MIXED);
	REGISTER_JUDY_CLASS_CONST_LONG("STRING_TO_INT", TYPE_STRING_TO_INT);
	REGISTER_JUDY_CLASS_CONST_LONG("STRING_TO_MIXED", TYPE_STRING_TO_MIXED);
	REGISTER_JUDY_CLASS_CONST_LONG("INT_TO_PACKED", TYPE_INT_TO_PACKED);

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
	} else if (ZEND_NUM_ARGS() > 0) {
		ZEND_PARSE_PARAMETERS_START(1, 1)
			Z_PARAM_LONG(type)
		ZEND_PARSE_PARAMETERS_END_EX(
			zend_restore_error_handling(&error_handling);
			return;
		);

		JTYPE(jtype, type);
		if (jtype == 0) {
			zend_restore_error_handling(&error_handling);
			return;
		}
#if !JUDY_MIXED_SUPPORTED
		if (jtype == TYPE_INT_TO_MIXED || jtype == TYPE_STRING_TO_MIXED
				|| jtype == TYPE_INT_TO_PACKED) {
			zend_throw_exception(NULL, "MIXED/PACKED Judy types are not supported on this platform (Word_t too small for pointers)", 0);
			zend_restore_error_handling(&error_handling);
			return;
		}
#endif
		intern->counter = 0;
		intern->type = jtype;
		intern->array = (Pvoid_t) NULL;

		/* Initialize cached type flags for performance optimization */
		intern->is_integer_keyed = (jtype == TYPE_BITSET || jtype == TYPE_INT_TO_INT || jtype == TYPE_INT_TO_MIXED || jtype == TYPE_INT_TO_PACKED);
		intern->is_string_keyed = (jtype == TYPE_STRING_TO_INT || jtype == TYPE_STRING_TO_MIXED);
		intern->is_mixed_value = (jtype == TYPE_INT_TO_MIXED || jtype == TYPE_STRING_TO_MIXED);
		intern->is_packed_value = (jtype == TYPE_INT_TO_PACKED);
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

	RETURN_LONG(judy_free_array_internal(intern));
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
			case TYPE_INT_TO_PACKED:
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
				|| intern->type == TYPE_INT_TO_MIXED || intern->type == TYPE_INT_TO_PACKED) {
			zend_long   zl_idx1 = 0;
			zend_long   zl_idx2 = -1;
			Word_t   idx1, idx2;
			Word_t   Rc_word;

			ZEND_PARSE_PARAMETERS_START(0, 2)
				Z_PARAM_OPTIONAL
				Z_PARAM_LONG(zl_idx1)
				Z_PARAM_LONG(zl_idx2)
			ZEND_PARSE_PARAMETERS_END();
			idx1 = (Word_t)zl_idx1;
			idx2 = (Word_t)zl_idx2;

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
				|| intern->type == TYPE_INT_TO_MIXED || intern->type == TYPE_INT_TO_PACKED) {
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
				|| intern->type == TYPE_INT_TO_MIXED || intern->type == TYPE_INT_TO_PACKED) {
			zend_long       nth_index;
			Word_t            index;

			ZEND_PARSE_PARAMETERS_START(1, 1)
				Z_PARAM_LONG(nth_index)
			ZEND_PARSE_PARAMETERS_END();

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
		zend_long       zl_index = 0;
		Word_t          index;
		int             Rc_int;

		ZEND_PARSE_PARAMETERS_START(0, 1)
			Z_PARAM_OPTIONAL
			Z_PARAM_LONG(zl_index)
		ZEND_PARSE_PARAMETERS_END();
		index = (Word_t)zl_index;

		J1F(Rc_int, intern->array, index);
		if (Rc_int == 1)
			RETURN_LONG(index);
	} else if (intern->type == TYPE_INT_TO_INT || intern->type == TYPE_INT_TO_MIXED
			|| intern->type == TYPE_INT_TO_PACKED) {
		zend_long       zl_index = 0;
		Word_t          index;
		PWord_t         PValue;

		ZEND_PARSE_PARAMETERS_START(0, 1)
			Z_PARAM_OPTIONAL
			Z_PARAM_LONG(zl_index)
		ZEND_PARSE_PARAMETERS_END();
		index = (Word_t)zl_index;

		JLF(PValue, intern->array, index);
		if (PValue != NULL && PValue != PJERR)
			RETURN_LONG(index);
	} else if (intern->type == TYPE_STRING_TO_INT || intern->type == TYPE_STRING_TO_MIXED) {
		char        *str;
		size_t       str_length = 0;

		uint8_t     key[PHP_JUDY_MAX_LENGTH];
		PWord_t     PValue;

		ZEND_PARSE_PARAMETERS_START(0, 1)
			Z_PARAM_OPTIONAL
			Z_PARAM_STRING(str, str_length)
		ZEND_PARSE_PARAMETERS_END();

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
		zend_long       zl_index;
		Word_t          index;
		int             Rc_int;

		ZEND_PARSE_PARAMETERS_START(1, 1)
			Z_PARAM_LONG(zl_index)
		ZEND_PARSE_PARAMETERS_END();
		index = (Word_t)zl_index;

		J1N(Rc_int, intern->array, index);
		if (Rc_int == 1)
			RETURN_LONG(index);
	} else if (intern->type == TYPE_INT_TO_INT || intern->type == TYPE_INT_TO_MIXED
			|| intern->type == TYPE_INT_TO_PACKED) {
		zend_long       zl_index;
		Word_t          index;
		PWord_t         PValue;

		ZEND_PARSE_PARAMETERS_START(1, 1)
			Z_PARAM_LONG(zl_index)
		ZEND_PARSE_PARAMETERS_END();
		index = (Word_t)zl_index;

		JLN(PValue, intern->array, index);
		if (PValue != NULL && PValue != PJERR)
			RETURN_LONG(index);
	} else if (intern->type == TYPE_STRING_TO_INT || intern->type == TYPE_STRING_TO_MIXED) {
		char        *str;
		size_t       str_length;

		uint8_t     key[PHP_JUDY_MAX_LENGTH];
		PWord_t     PValue;

		ZEND_PARSE_PARAMETERS_START(1, 1)
			Z_PARAM_STRING(str, str_length)
		ZEND_PARSE_PARAMETERS_END();

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
				ZVAL_LONG(&intern->iterator_data, JUDY_LVAL_READ(PValue));
			} else if (intern->type == TYPE_INT_TO_PACKED) {
				judy_packed_value *packed = JUDY_PVAL_READ(PValue);
				if (packed) {
					judy_unpack_value(packed, &intern->iterator_data);
				}
			} else {
				zval *value = JUDY_MVAL_READ(PValue);
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
				zval *value = JUDY_MVAL_READ(PValue);
				ZVAL_COPY(&intern->iterator_data, value);
			} else {
				ZVAL_LONG(&intern->iterator_data, JUDY_LVAL_READ(PValue));
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

			if (JUDY_IS_PACKED_VALUE(intern)) {
				judy_packed_value *packed = JUDY_PVAL_READ(PValue);
				if (packed) {
					judy_unpack_value(packed, &intern->iterator_data);
				}
			} else if (JUDY_IS_MIXED_VALUE(intern)) {
				zval *value = JUDY_MVAL_READ(PValue);
				ZVAL_COPY(&intern->iterator_data, value);
			} else {
				ZVAL_LONG(&intern->iterator_data, JUDY_LVAL_READ(PValue));
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
				zval *value = JUDY_MVAL_READ(PValue);
				ZVAL_COPY(&intern->iterator_data, value);
			} else {
				ZVAL_LONG(&intern->iterator_data, JUDY_LVAL_READ(PValue));
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
		zend_long    zl_index = -1;
		Word_t       index;
		int          Rc_int;

		ZEND_PARSE_PARAMETERS_START(0, 1)
			Z_PARAM_OPTIONAL
			Z_PARAM_LONG(zl_index)
		ZEND_PARSE_PARAMETERS_END();
		index = (Word_t)zl_index;

		J1L(Rc_int, intern->array, index);
		if (Rc_int == 1)
			RETURN_LONG(index);
	} else if (intern->type == TYPE_INT_TO_INT || intern->type == TYPE_INT_TO_MIXED
			|| intern->type == TYPE_INT_TO_PACKED) {
		zend_long       zl_index = -1;
		Word_t          index;
		PWord_t         PValue;

		ZEND_PARSE_PARAMETERS_START(0, 1)
			Z_PARAM_OPTIONAL
			Z_PARAM_LONG(zl_index)
		ZEND_PARSE_PARAMETERS_END();
		index = (Word_t)zl_index;

		JLL(PValue, intern->array, index);
		if (PValue != NULL && PValue != PJERR)
			RETURN_LONG(index);
	} else if (intern->type == TYPE_STRING_TO_INT || intern->type == TYPE_STRING_TO_MIXED) {
		char        *str;
		size_t       str_length = 0;

		uint8_t     key[PHP_JUDY_MAX_LENGTH];
		PWord_t     PValue;

		ZEND_PARSE_PARAMETERS_START(0, 1)
			Z_PARAM_OPTIONAL
			Z_PARAM_STRING(str, str_length)
		ZEND_PARSE_PARAMETERS_END();

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
		zend_long    zl_index;
		Word_t       index;
		int          Rc_int;

		ZEND_PARSE_PARAMETERS_START(1, 1)
			Z_PARAM_LONG(zl_index)
		ZEND_PARSE_PARAMETERS_END();
		index = (Word_t)zl_index;

		J1P(Rc_int, intern->array, index);
		if (Rc_int == 1)
			RETURN_LONG(index);
	} else if (intern->type == TYPE_INT_TO_INT || intern->type == TYPE_INT_TO_MIXED
			|| intern->type == TYPE_INT_TO_PACKED) {
		zend_long       zl_index;
		Word_t          index;
		PWord_t         PValue;

		ZEND_PARSE_PARAMETERS_START(1, 1)
			Z_PARAM_LONG(zl_index)
		ZEND_PARSE_PARAMETERS_END();
		index = (Word_t)zl_index;

		JLP(PValue, intern->array, index);
		if (PValue != NULL && PValue != PJERR)
			RETURN_LONG(index);
	} else if (intern->type == TYPE_STRING_TO_INT || intern->type == TYPE_STRING_TO_MIXED) {
		char        *str;
		size_t       str_length;

		uint8_t     key[PHP_JUDY_MAX_LENGTH];
		PWord_t     PValue;

		ZEND_PARSE_PARAMETERS_START(1, 1)
			Z_PARAM_STRING(str, str_length)
		ZEND_PARSE_PARAMETERS_END();

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
	zend_long      zl_index = 0;
	Word_t         index;
	int            Rc_int = 0;

	JUDY_METHOD_GET_OBJECT

	ZEND_PARSE_PARAMETERS_START(0, 1)
		Z_PARAM_OPTIONAL
		Z_PARAM_LONG(zl_index)
	ZEND_PARSE_PARAMETERS_END();
	index = (Word_t)zl_index;

	switch (intern->type)
	{
		case TYPE_BITSET:
			J1FE(Rc_int, intern->array, index);
			break;
		case TYPE_INT_TO_INT:
		case TYPE_INT_TO_MIXED:
		case TYPE_INT_TO_PACKED:
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
	zend_long      zl_index = -1;
	Word_t         index;
	int            Rc_int = 0;

	JUDY_METHOD_GET_OBJECT

	ZEND_PARSE_PARAMETERS_START(0, 1)
		Z_PARAM_OPTIONAL
		Z_PARAM_LONG(zl_index)
	ZEND_PARSE_PARAMETERS_END();
	index = (Word_t)zl_index;

	switch (intern->type)
	{
		case TYPE_BITSET:
			J1LE(Rc_int, intern->array, index);
			break;
		case TYPE_INT_TO_INT:
		case TYPE_INT_TO_MIXED:
		case TYPE_INT_TO_PACKED:
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
	zend_long      zl_index;
	Word_t         index;
	int            Rc_int = 0;

	JUDY_METHOD_GET_OBJECT

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_LONG(zl_index)
	ZEND_PARSE_PARAMETERS_END();
	index = (Word_t)zl_index;

	switch (intern->type)
	{
		case TYPE_BITSET:
			J1NE(Rc_int, intern->array, index);
			break;
		case TYPE_INT_TO_INT:
		case TYPE_INT_TO_MIXED:
		case TYPE_INT_TO_PACKED:
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
	zend_long      zl_index;
	Word_t         index;
	int            Rc_int = 0;

	JUDY_METHOD_GET_OBJECT

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_LONG(zl_index)
	ZEND_PARSE_PARAMETERS_END();
	index = (Word_t)zl_index;

	switch (intern->type)
	{
		case TYPE_BITSET:
			J1PE(Rc_int, intern->array, index);
			break;
		case TYPE_INT_TO_INT:
		case TYPE_INT_TO_MIXED:
		case TYPE_INT_TO_PACKED:
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

/* {{{ Helper to create a new empty BITSET Judy object as return_value */
static void judy_create_bitset_result(zval *return_value)
{
	judy_object *result;

	object_init_ex(return_value, judy_ce);
	result = php_judy_object(Z_OBJ_P(return_value));
	result->type = TYPE_BITSET;
	result->array = (Pvoid_t) NULL;
	result->counter = 0;
	result->is_integer_keyed = 1;
	result->is_string_keyed = 0;
	result->is_mixed_value = 0;
}
/* }}} */

/* {{{ Helper to validate that both operands are BITSET type */
static int judy_validate_bitset_operands(judy_object *self, judy_object *other)
{
	if (self->type != TYPE_BITSET) {
		zend_throw_exception(NULL, "Set operations are only supported on BITSET arrays", 0);
		return FAILURE;
	}
	if (other->type != TYPE_BITSET) {
		zend_throw_exception(NULL, "The other Judy array must also be a BITSET", 0);
		return FAILURE;
	}
	return SUCCESS;
}
/* }}} */

/* {{{ proto Judy Judy::union(Judy $other)
   Return a new BITSET containing all indices present in either array */
PHP_METHOD(judy, union)
{
	zval *other_zval;
	judy_object *other, *result;
	Word_t index;
	int Rc_int, Rc_set;

	JUDY_METHOD_GET_OBJECT

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_OBJECT_OF_CLASS(other_zval, judy_ce)
	ZEND_PARSE_PARAMETERS_END();

	other = php_judy_object(Z_OBJ_P(other_zval));

	if (judy_validate_bitset_operands(intern, other) == FAILURE) {
		return;
	}

	judy_create_bitset_result(return_value);
	result = php_judy_object(Z_OBJ_P(return_value));

	/* Add all indices from self */
	index = 0;
	J1F(Rc_int, intern->array, index);
	while (Rc_int) {
		J1S(Rc_set, result->array, index);
		if (Rc_set == JERR) goto alloc_error;
		J1N(Rc_int, intern->array, index);
	}

	/* Add all indices from other */
	index = 0;
	J1F(Rc_int, other->array, index);
	while (Rc_int) {
		J1S(Rc_set, result->array, index);
		if (Rc_set == JERR) goto alloc_error;
		J1N(Rc_int, other->array, index);
	}

	return;

alloc_error:
	J1FA(Rc_int, result->array);
	result->array = NULL;
	zval_ptr_dtor(return_value);
	ZVAL_NULL(return_value);
	zend_throw_exception(NULL, "Judy: memory allocation failed during union", 0);
}
/* }}} */

/* {{{ proto Judy Judy::intersect(Judy $other)
   Return a new BITSET containing only indices present in both arrays */
PHP_METHOD(judy, intersect)
{
	zval *other_zval;
	judy_object *other, *result;
	Pvoid_t iter_array, test_array;
	Word_t index;
	int Rc_int, Rc_set;

	JUDY_METHOD_GET_OBJECT

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_OBJECT_OF_CLASS(other_zval, judy_ce)
	ZEND_PARSE_PARAMETERS_END();

	other = php_judy_object(Z_OBJ_P(other_zval));

	if (judy_validate_bitset_operands(intern, other) == FAILURE) {
		return;
	}

	judy_create_bitset_result(return_value);
	result = php_judy_object(Z_OBJ_P(return_value));

	/* Iterate the smaller set, test against the larger for better performance */
	{
		Word_t count_self = 0, count_other = 0;
		J1C(count_self, intern->array, 0, -1);
		J1C(count_other, other->array, 0, -1);
		if (count_self <= count_other) {
			iter_array = intern->array;
			test_array = other->array;
		} else {
			iter_array = other->array;
			test_array = intern->array;
		}
	}

	index = 0;
	J1F(Rc_int, iter_array, index);
	while (Rc_int) {
		int in_test;
		J1T(in_test, test_array, index);
		if (in_test) {
			J1S(Rc_set, result->array, index);
			if (Rc_set == JERR) goto alloc_error;
		}
		J1N(Rc_int, iter_array, index);
	}

	return;

alloc_error:
	J1FA(Rc_int, result->array);
	result->array = NULL;
	zval_ptr_dtor(return_value);
	ZVAL_NULL(return_value);
	zend_throw_exception(NULL, "Judy: memory allocation failed during intersect", 0);
}
/* }}} */

/* {{{ proto Judy Judy::diff(Judy $other)
   Return a new BITSET containing indices present in this array but not in $other */
PHP_METHOD(judy, diff)
{
	zval *other_zval;
	judy_object *other, *result;
	Word_t index;
	int Rc_int, Rc_set;

	JUDY_METHOD_GET_OBJECT

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_OBJECT_OF_CLASS(other_zval, judy_ce)
	ZEND_PARSE_PARAMETERS_END();

	other = php_judy_object(Z_OBJ_P(other_zval));

	if (judy_validate_bitset_operands(intern, other) == FAILURE) {
		return;
	}

	judy_create_bitset_result(return_value);
	result = php_judy_object(Z_OBJ_P(return_value));

	/* Iterate self, add to result only if absent in other */
	index = 0;
	J1F(Rc_int, intern->array, index);
	while (Rc_int) {
		int in_other;
		J1T(in_other, other->array, index);
		if (!in_other) {
			J1S(Rc_set, result->array, index);
			if (Rc_set == JERR) goto alloc_error;
		}
		J1N(Rc_int, intern->array, index);
	}

	return;

alloc_error:
	J1FA(Rc_int, result->array);
	result->array = NULL;
	zval_ptr_dtor(return_value);
	ZVAL_NULL(return_value);
	zend_throw_exception(NULL, "Judy: memory allocation failed during diff", 0);
}
/* }}} */

/* {{{ proto Judy Judy::xor(Judy $other)
   Return a new BITSET containing indices present in exactly one of the arrays */
PHP_METHOD(judy, xor)
{
	zval *other_zval;
	judy_object *other, *result;
	Word_t index;
	int Rc_int, Rc_set;

	JUDY_METHOD_GET_OBJECT

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_OBJECT_OF_CLASS(other_zval, judy_ce)
	ZEND_PARSE_PARAMETERS_END();

	other = php_judy_object(Z_OBJ_P(other_zval));

	if (judy_validate_bitset_operands(intern, other) == FAILURE) {
		return;
	}

	judy_create_bitset_result(return_value);
	result = php_judy_object(Z_OBJ_P(return_value));

	/* Add indices from self that are not in other */
	index = 0;
	J1F(Rc_int, intern->array, index);
	while (Rc_int) {
		int in_other;
		J1T(in_other, other->array, index);
		if (!in_other) {
			J1S(Rc_set, result->array, index);
			if (Rc_set == JERR) goto alloc_error;
		}
		J1N(Rc_int, intern->array, index);
	}

	/* Add indices from other that are not in self */
	index = 0;
	J1F(Rc_int, other->array, index);
	while (Rc_int) {
		int in_self;
		J1T(in_self, intern->array, index);
		if (!in_self) {
			J1S(Rc_set, result->array, index);
			if (Rc_set == JERR) goto alloc_error;
		}
		J1N(Rc_int, other->array, index);
	}

	return;

alloc_error:
	J1FA(Rc_int, result->array);
	result->array = NULL;
	zval_ptr_dtor(return_value);
	ZVAL_NULL(return_value);
	zend_throw_exception(NULL, "Judy: memory allocation failed during xor", 0);
}
/* }}} */

/* {{{ Helper to create a new empty Judy object of the given type as return_value */
static judy_object *judy_create_result(zval *return_value, judy_type type)
{
	judy_object *result;

	object_init_ex(return_value, judy_ce);
	result = php_judy_object(Z_OBJ_P(return_value));
	result->type = type;
	result->array = (Pvoid_t) NULL;
	result->counter = 0;
	result->is_integer_keyed = (type == TYPE_BITSET || type == TYPE_INT_TO_INT || type == TYPE_INT_TO_MIXED || type == TYPE_INT_TO_PACKED);
	result->is_string_keyed = (type == TYPE_STRING_TO_INT || type == TYPE_STRING_TO_MIXED);
	result->is_mixed_value = (type == TYPE_INT_TO_MIXED || type == TYPE_STRING_TO_MIXED);
	result->is_packed_value = (type == TYPE_INT_TO_PACKED);
	return result;
}
/* }}} */

/* {{{ proto Judy Judy::slice(mixed $start, mixed $end)
   Return a new Judy array of the same type containing entries in [$start, $end] inclusive */
PHP_METHOD(judy, slice)
{
	zval *zstart, *zend_val;
	judy_object *result;

	JUDY_METHOD_GET_OBJECT

	ZEND_PARSE_PARAMETERS_START(2, 2)
		Z_PARAM_ZVAL(zstart)
		Z_PARAM_ZVAL(zend_val)
	ZEND_PARSE_PARAMETERS_END();

	result = judy_create_result(return_value, intern->type);

	if (intern->type == TYPE_BITSET) {
		Word_t start = (Word_t) zval_get_long(zstart);
		Word_t end = (Word_t) zval_get_long(zend_val);
		Word_t index;
		int Rc_int, Rc_set;

		if (start > end) return;

		index = start;
		J1F(Rc_int, intern->array, index);
		while (Rc_int && index <= end) {
			J1S(Rc_set, result->array, index);
			if (Rc_set == JERR) goto alloc_error;
			J1N(Rc_int, intern->array, index);
		}

	} else if (intern->type == TYPE_INT_TO_INT) {
		Word_t start = (Word_t) zval_get_long(zstart);
		Word_t end = (Word_t) zval_get_long(zend_val);
		Word_t index;
		Pvoid_t *PValue;

		if (start > end) return;

		index = start;
		JLF(PValue, intern->array, index);
		while (PValue != NULL && PValue != PJERR && index <= end) {
			Pvoid_t *PNew;
			JLI(PNew, result->array, index);
			if (PNew == PJERR) goto alloc_error;
			JUDY_LVAL_WRITE(PNew, JUDY_LVAL_READ(PValue));
			JLN(PValue, intern->array, index);
		}

	} else if (intern->type == TYPE_INT_TO_MIXED) {
		Word_t start = (Word_t) zval_get_long(zstart);
		Word_t end = (Word_t) zval_get_long(zend_val);
		Word_t index;
		Pvoid_t *PValue;

		if (start > end) return;

		index = start;
		JLF(PValue, intern->array, index);
		while (PValue != NULL && PValue != PJERR && index <= end) {
			Pvoid_t *PNew;
			zval *new_value;
			JLI(PNew, result->array, index);
			if (PNew == PJERR) goto alloc_error;
			new_value = ecalloc(1, sizeof(zval));
			ZVAL_COPY(new_value, JUDY_MVAL_READ(PValue));
			JUDY_MVAL_WRITE(PNew, new_value);
			JLN(PValue, intern->array, index);
		}

	} else if (intern->type == TYPE_INT_TO_PACKED) {
		Word_t start = (Word_t) zval_get_long(zstart);
		Word_t end = (Word_t) zval_get_long(zend_val);
		Word_t index;
		Pvoid_t *PValue;

		if (start > end) return;

		index = start;
		JLF(PValue, intern->array, index);
		while (PValue != NULL && PValue != PJERR && index <= end) {
			Pvoid_t *PNew;
			JLI(PNew, result->array, index);
			if (PNew == PJERR) goto alloc_error;

			judy_packed_value *src = JUDY_PVAL_READ(PValue);
			if (src) {
				judy_packed_value *dst = emalloc(sizeof(judy_packed_value) + src->len);
				dst->len = src->len;
				memcpy(dst->data, src->data, src->len);
				JUDY_PVAL_WRITE(PNew, dst);
			} else {
				JUDY_PVAL_WRITE(PNew, NULL);
			}
			JLN(PValue, intern->array, index);
		}

	} else if (intern->type == TYPE_STRING_TO_INT || intern->type == TYPE_STRING_TO_MIXED) {
		char *str_start, *str_end;
		size_t str_start_len, str_end_len;

		if (Z_TYPE_P(zstart) != IS_STRING || Z_TYPE_P(zend_val) != IS_STRING) {
			zend_throw_error(zend_ce_type_error, "Judy::slice() expects string arguments for string-keyed arrays");
			return;
		}

		str_start = Z_STRVAL_P(zstart);
		str_start_len = Z_STRLEN_P(zstart);
		str_end = Z_STRVAL_P(zend_val);
		str_end_len = Z_STRLEN_P(zend_val);

		if (strcmp(str_start, str_end) > 0) return;

		{
			uint8_t key[PHP_JUDY_MAX_LENGTH];
			Pvoid_t *PValue;
			int key_len;

			key_len = str_start_len >= PHP_JUDY_MAX_LENGTH ? PHP_JUDY_MAX_LENGTH - 1 : str_start_len;
			memcpy(key, str_start, key_len);
			key[key_len] = '\0';

			JSLF(PValue, intern->array, key);
			while (PValue != NULL && PValue != PJERR && strcmp((const char *)key, str_end) <= 0) {
				Pvoid_t *PNew;
				JSLI(PNew, result->array, key);
				if (PNew == PJERR) goto alloc_error;

				if (intern->type == TYPE_STRING_TO_INT) {
					JUDY_LVAL_WRITE(PNew, JUDY_LVAL_READ(PValue));
				} else {
					zval *new_value;
					new_value = ecalloc(1, sizeof(zval));
					ZVAL_COPY(new_value, JUDY_MVAL_READ(PValue));
					JUDY_MVAL_WRITE(PNew, new_value);
				}
				result->counter++;
				JSLN(PValue, intern->array, key);
			}
		}
	}

	return;

alloc_error:
	zval_ptr_dtor(return_value);
	ZVAL_NULL(return_value);
	zend_throw_exception(NULL, "Judy: memory allocation failed during slice", 0);
}
/* }}} */

/* {{{ Helper to build a PHP array from a Judy array's contents.
   Used by both jsonSerialize() and __serialize() to avoid duplication.
   Populates `data` (which must be initialized as an array by the caller). */
static void judy_build_data_array(judy_object *intern, zval *data)
{
	if (intern->type == TYPE_BITSET) {
		Word_t index = 0;
		int Rc_int;

		J1F(Rc_int, intern->array, index);
		while (Rc_int) {
			add_next_index_long(data, (zend_long)index);
			J1N(Rc_int, intern->array, index);
		}

	} else if (intern->type == TYPE_INT_TO_INT) {
		Word_t index = 0;
		Pvoid_t *PValue;

		JLF(PValue, intern->array, index);
		while (PValue != NULL && PValue != PJERR) {
			add_index_long(data, (zend_long)index, JUDY_LVAL_READ(PValue));
			JLN(PValue, intern->array, index);
		}

	} else if (intern->type == TYPE_INT_TO_MIXED) {
		Word_t index = 0;
		Pvoid_t *PValue;

		JLF(PValue, intern->array, index);
		while (PValue != NULL && PValue != PJERR) {
			zval *value = JUDY_MVAL_READ(PValue);
			Z_TRY_ADDREF_P(value);
			add_index_zval(data, (zend_long)index, value);
			JLN(PValue, intern->array, index);
		}

	} else if (intern->type == TYPE_INT_TO_PACKED) {
		Word_t index = 0;
		Pvoid_t *PValue;

		JLF(PValue, intern->array, index);
		while (PValue != NULL && PValue != PJERR) {
			judy_packed_value *packed = JUDY_PVAL_READ(PValue);
			if (packed) {
				zval tmp;
				ZVAL_UNDEF(&tmp);
				judy_unpack_value(packed, &tmp);
				add_index_zval(data, (zend_long)index, &tmp);
			} else {
				add_index_null(data, (zend_long)index);
			}
			JLN(PValue, intern->array, index);
		}

	} else if (intern->type == TYPE_STRING_TO_INT) {
		uint8_t key[PHP_JUDY_MAX_LENGTH];
		Pvoid_t *PValue;

		key[0] = '\0';
		JSLF(PValue, intern->array, key);
		while (PValue != NULL && PValue != PJERR) {
			add_assoc_long(data, (const char *)key, JUDY_LVAL_READ(PValue));
			JSLN(PValue, intern->array, key);
		}

	} else if (intern->type == TYPE_STRING_TO_MIXED) {
		uint8_t key[PHP_JUDY_MAX_LENGTH];
		Pvoid_t *PValue;

		key[0] = '\0';
		JSLF(PValue, intern->array, key);
		while (PValue != NULL && PValue != PJERR) {
			zval *value = JUDY_MVAL_READ(PValue);
			Z_TRY_ADDREF_P(value);
			add_assoc_zval(data, (const char *)key, value);
			JSLN(PValue, intern->array, key);
		}
	}
}
/* }}} */

/* {{{ proto mixed Judy::jsonSerialize()
   Returns data suitable for json_encode(). Implements JsonSerializable. */
PHP_METHOD(judy, jsonSerialize)
{
	JUDY_METHOD_GET_OBJECT

	array_init(return_value);
	judy_build_data_array(intern, return_value);
}
/* }}} */

/* {{{ proto array Judy::__serialize()
   Returns serialization data: ['type' => int, 'data' => array] */
PHP_METHOD(judy, __serialize)
{
	JUDY_METHOD_GET_OBJECT

	zval data;

	array_init(return_value);
	add_assoc_long(return_value, "type", intern->type);

	array_init(&data);
	judy_build_data_array(intern, &data);
	add_assoc_zval(return_value, "data", &data);
}
/* }}} */

/* {{{ proto void Judy::__unserialize(array $data)
   Restores a Judy array from serialized data */
PHP_METHOD(judy, __unserialize)
{
	zval *arr, *ztype, *zdata, *entry;
	zend_long type;
	judy_type jtype;
	zend_string *str_key;
	zend_ulong num_key;

	JUDY_METHOD_GET_OBJECT

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_ARRAY(arr)
	ZEND_PARSE_PARAMETERS_END();

	ztype = zend_hash_str_find(Z_ARRVAL_P(arr), "type", sizeof("type") - 1);
	zdata = zend_hash_str_find(Z_ARRVAL_P(arr), "data", sizeof("data") - 1);

	if (!ztype || !zdata || Z_TYPE_P(ztype) != IS_LONG || Z_TYPE_P(zdata) != IS_ARRAY) {
		zend_throw_exception(NULL, "Invalid serialization data for Judy array", 0);
		return;
	}

	type = Z_LVAL_P(ztype);
	JTYPE(jtype, type);
	if (jtype == 0) {
		zend_throw_exception(NULL, "Invalid Judy type in serialized data", 0);
		return;
	}

	intern->type = jtype;
	intern->array = (Pvoid_t) NULL;
	intern->counter = 0;
	intern->is_integer_keyed = (jtype == TYPE_BITSET || jtype == TYPE_INT_TO_INT || jtype == TYPE_INT_TO_MIXED || jtype == TYPE_INT_TO_PACKED);
	intern->is_string_keyed = (jtype == TYPE_STRING_TO_INT || jtype == TYPE_STRING_TO_MIXED);
	intern->is_mixed_value = (jtype == TYPE_INT_TO_MIXED || jtype == TYPE_STRING_TO_MIXED);
	intern->is_packed_value = (jtype == TYPE_INT_TO_PACKED);

	ZEND_HASH_FOREACH_KEY_VAL(Z_ARRVAL_P(zdata), num_key, str_key, entry) {
		zval offset, *val_to_write;

		if (intern->type == TYPE_BITSET) {
			/* BITSET data is a flat array of index values */
			ZVAL_LONG(&offset, zval_get_long(entry));
			zval bool_true;
			ZVAL_TRUE(&bool_true);
			judy_object_write_dimension_helper(object, &offset, &bool_true);
		} else if (intern->is_string_keyed) {
			if (str_key) {
				ZVAL_STR_COPY(&offset, str_key);
			} else {
				/* Numeric key in string array -- convert to string */
				ZVAL_STR(&offset, zend_long_to_str((zend_long)num_key));
			}
			val_to_write = entry;
			judy_object_write_dimension_helper(object, &offset, val_to_write);
			zval_ptr_dtor(&offset);
		} else {
			/* Integer-keyed types */
			ZVAL_LONG(&offset, (zend_long)num_key);
			val_to_write = entry;
			judy_object_write_dimension_helper(object, &offset, val_to_write);
		}
	} ZEND_HASH_FOREACH_END();
}
/* }}} */

/* {{{ proto array Judy::toArray()
   Convert the Judy array to a native PHP array */
PHP_METHOD(judy, toArray)
{
	JUDY_METHOD_GET_OBJECT

	ZEND_PARSE_PARAMETERS_NONE();

	array_init(return_value);
	judy_build_data_array(intern, return_value);
}
/* }}} */

/* {{{ judy_populate_from_array — shared helper for fromArray() and putAll() */
static void judy_populate_from_array(zval *judy_obj, zval *arr) {
	judy_object *intern = php_judy_object(Z_OBJ_P(judy_obj));
	zval *entry, offset;
	zend_string *str_key;
	zend_ulong num_key;

	ZEND_HASH_FOREACH_KEY_VAL(Z_ARRVAL_P(arr), num_key, str_key, entry) {
		if (intern->type == TYPE_BITSET) {
			ZVAL_LONG(&offset, zval_get_long(entry));
			zval bool_true;
			ZVAL_TRUE(&bool_true);
			judy_object_write_dimension_helper(judy_obj, &offset, &bool_true);
		} else if (intern->is_string_keyed) {
			if (str_key) {
				ZVAL_STR_COPY(&offset, str_key);
			} else {
				ZVAL_STR(&offset, zend_long_to_str((zend_long)num_key));
			}
			judy_object_write_dimension_helper(judy_obj, &offset, entry);
			zval_ptr_dtor(&offset);
		} else {
			ZVAL_LONG(&offset, (zend_long)num_key);
			judy_object_write_dimension_helper(judy_obj, &offset, entry);
		}
	} ZEND_HASH_FOREACH_END();
}
/* }}} */

/* {{{ proto Judy Judy::fromArray(int $type, array $data)
   Static factory: create a new Judy array from a PHP array */
PHP_METHOD(judy, fromArray)
{
	zend_long type;
	judy_type jtype;
	zval *arr;

	ZEND_PARSE_PARAMETERS_START(2, 2)
		Z_PARAM_LONG(type)
		Z_PARAM_ARRAY(arr)
	ZEND_PARSE_PARAMETERS_END();

	JTYPE(jtype, type);
	if (jtype == 0) {
		zend_throw_exception(NULL, "Invalid Judy type", 0);
		return;
	}

	judy_create_result(return_value, jtype);
	judy_populate_from_array(return_value, arr);
}
/* }}} */

/* {{{ proto void Judy::putAll(array $data)
   Bulk-insert entries from a PHP array into this Judy array */
PHP_METHOD(judy, putAll)
{
	zval *arr;

	JUDY_METHOD_GET_OBJECT

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_ARRAY(arr)
	ZEND_PARSE_PARAMETERS_END();

	judy_populate_from_array(object, arr);
}
/* }}} */

/* {{{ proto array Judy::getAll(array $keys)
   Retrieve multiple values at once. Returns key => value (or null if absent). */
PHP_METHOD(judy, getAll)
{
	zval *keys, *key_entry;

	JUDY_METHOD_GET_OBJECT

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_ARRAY(keys)
	ZEND_PARSE_PARAMETERS_END();

	array_init(return_value);

	ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(keys), key_entry) {
		if (intern->is_integer_keyed) {
			Word_t index = (Word_t)zval_get_long(key_entry);
			if (intern->type == TYPE_BITSET) {
				int Rc_int;
				J1T(Rc_int, intern->array, index);
				add_index_bool(return_value, (zend_long)index, Rc_int);
			} else {
				Pvoid_t *PValue;
				JLG(PValue, intern->array, index);
				if (PValue == NULL || PValue == PJERR) {
					add_index_null(return_value, (zend_long)index);
				} else if (intern->type == TYPE_INT_TO_INT) {
					add_index_long(return_value, (zend_long)index, JUDY_LVAL_READ(PValue));
				} else if (intern->type == TYPE_INT_TO_PACKED) {
					judy_packed_value *packed = JUDY_PVAL_READ(PValue);
					if (packed) {
						zval tmp;
						ZVAL_UNDEF(&tmp);
						judy_unpack_value(packed, &tmp);
						add_index_zval(return_value, (zend_long)index, &tmp);
					} else {
						add_index_null(return_value, (zend_long)index);
					}
				} else { /* TYPE_INT_TO_MIXED */
					if (JUDY_MVAL_READ(PValue) != NULL) {
						zval *value = JUDY_MVAL_READ(PValue);
						Z_TRY_ADDREF_P(value);
						add_index_zval(return_value, (zend_long)index, value);
					} else {
						add_index_null(return_value, (zend_long)index);
					}
				}
			}
		} else { /* is_string_keyed */
			zend_string *skey = zval_get_string(key_entry);
			Pvoid_t *PValue;
			JSLG(PValue, intern->array, (uint8_t *)ZSTR_VAL(skey));
			if (PValue == NULL || PValue == PJERR) {
				add_assoc_null(return_value, ZSTR_VAL(skey));
			} else if (intern->type == TYPE_STRING_TO_INT) {
				add_assoc_long(return_value, ZSTR_VAL(skey), JUDY_LVAL_READ(PValue));
			} else { /* TYPE_STRING_TO_MIXED */
				if (JUDY_MVAL_READ(PValue) != NULL) {
					zval *value = JUDY_MVAL_READ(PValue);
					Z_TRY_ADDREF_P(value);
					add_assoc_zval(return_value, ZSTR_VAL(skey), value);
				} else {
					add_assoc_null(return_value, ZSTR_VAL(skey));
				}
			}
			zend_string_release(skey);
		}
	} ZEND_HASH_FOREACH_END();
}
/* }}} */

/* {{{ proto int Judy::increment(mixed $key, int $amount = 1)
   Atomic increment for INT_TO_INT (single-traversal via JLI) and
   STRING_TO_INT (two traversals: JSLG for counter tracking + JSLI).
   Returns the new value. Creates the key with value $amount if it doesn't exist. */
PHP_METHOD(judy, increment)
{
	zval *zkey;
	zend_long amount = 1;

	JUDY_METHOD_GET_OBJECT

	ZEND_PARSE_PARAMETERS_START(1, 2)
		Z_PARAM_ZVAL(zkey)
		Z_PARAM_OPTIONAL
		Z_PARAM_LONG(amount)
	ZEND_PARSE_PARAMETERS_END();

	if (intern->type == TYPE_INT_TO_INT) {
		Word_t index = (Word_t)zval_get_long(zkey);
		Pvoid_t *PValue;

		JLI(PValue, intern->array, index);
		if (PValue == NULL || PValue == PJERR) {
			zend_throw_exception(NULL, "Judy: memory allocation failed during increment", 0);
			return;
		}
		zend_long old_val = JUDY_LVAL_READ(PValue);
		JUDY_LVAL_WRITE(PValue, old_val + amount);
		RETURN_LONG(old_val + amount);

	} else if (intern->type == TYPE_STRING_TO_INT) {
		zend_string *skey = zval_get_string(zkey);
		Pvoid_t *PExisting;
		Pvoid_t *PValue;

		if (ZSTR_LEN(skey) >= PHP_JUDY_MAX_LENGTH) {
			zend_string_release(skey);
			zend_throw_exception_ex(NULL, 0,
				"Judy string key length (%zu) exceeds maximum of %d bytes",
				ZSTR_LEN(skey), PHP_JUDY_MAX_LENGTH - 1);
			return;
		}

		/* Check if key exists for counter tracking (requires JSLG + JSLI) */
		JSLG(PExisting, intern->array, (uint8_t *)ZSTR_VAL(skey));

		JSLI(PValue, intern->array, (uint8_t *)ZSTR_VAL(skey));
		if (PValue == NULL || PValue == PJERR) {
			zend_string_release(skey);
			zend_throw_exception(NULL, "Judy: memory allocation failed during increment", 0);
			return;
		}

		if (PExisting == NULL) {
			intern->counter++;
		}

		zend_long old_val = JUDY_LVAL_READ(PValue);
		JUDY_LVAL_WRITE(PValue, old_val + amount);
		zend_string_release(skey);
		RETURN_LONG(old_val + amount);

	} else {
		zend_throw_exception(NULL, "Judy::increment() is only supported for INT_TO_INT and STRING_TO_INT types", 0);
		return;
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

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_OBJECT_OF_CLASS(object, judy_ce)
	ZEND_PARSE_PARAMETERS_END();

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
PHP_METHOD(judy, union);
PHP_METHOD(judy, intersect);
PHP_METHOD(judy, diff);
PHP_METHOD(judy, xor);
PHP_METHOD(judy, slice);
PHP_METHOD(judy, jsonSerialize);
PHP_METHOD(judy, __serialize);
PHP_METHOD(judy, __unserialize);
PHP_METHOD(judy, toArray);
PHP_METHOD(judy, fromArray);
PHP_METHOD(judy, putAll);
PHP_METHOD(judy, getAll);
PHP_METHOD(judy, increment);
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

/* Bitset set operations arginfo */
ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_judy_union, 0, 1, Judy, 0)
	ZEND_ARG_OBJ_INFO(0, other, Judy, 0)
ZEND_END_ARG_INFO()

#define arginfo_judy_intersect arginfo_judy_union
#define arginfo_judy_diff arginfo_judy_union
#define arginfo_judy_xor arginfo_judy_union

/* Slice arginfo */
ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_judy_slice, 0, 2, Judy, 0)
	ZEND_ARG_TYPE_INFO(0, start, IS_MIXED, 0)
	ZEND_ARG_TYPE_INFO(0, end, IS_MIXED, 0)
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

/* JsonSerializable / Serialization arginfo */
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_judy_jsonSerialize, 0, 0, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_judy___serialize, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_judy___unserialize, 0, 1, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, data, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

/* Batch operations and increment arginfo */
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_judy_toArray, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_judy_fromArray, 0, 2, Judy, 0)
	ZEND_ARG_TYPE_INFO(0, type, IS_LONG, 0)
	ZEND_ARG_TYPE_INFO(0, data, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_judy_putAll, 0, 1, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, data, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_judy_getAll, 0, 1, IS_ARRAY, 0)
	ZEND_ARG_TYPE_INFO(0, keys, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_judy_increment, 0, 1, IS_LONG, 0)
	ZEND_ARG_TYPE_INFO(0, key, IS_MIXED, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, amount, IS_LONG, 0, "1")
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

	/* PHP JUDY METHODS / BITSET SET OPERATIONS */
	PHP_ME(judy, union, 			arginfo_judy_union, ZEND_ACC_PUBLIC)
	PHP_ME(judy, intersect, 		arginfo_judy_intersect, ZEND_ACC_PUBLIC)
	PHP_ME(judy, diff, 				arginfo_judy_diff, ZEND_ACC_PUBLIC)
	PHP_ME(judy, xor, 				arginfo_judy_xor, ZEND_ACC_PUBLIC)

	/* PHP JUDY METHODS / SLICE */
	PHP_ME(judy, slice, 			arginfo_judy_slice, ZEND_ACC_PUBLIC)

	/* PHP JUDY METHODS / SERIALIZATION */
	PHP_ME(judy, jsonSerialize, 	arginfo_judy_jsonSerialize, ZEND_ACC_PUBLIC)
	PHP_ME(judy, __serialize, 		arginfo_judy___serialize, ZEND_ACC_PUBLIC)
	PHP_ME(judy, __unserialize, 	arginfo_judy___unserialize, ZEND_ACC_PUBLIC)

	/* PHP JUDY METHODS / BATCH OPERATIONS AND INCREMENT */
	PHP_ME(judy, toArray, 			arginfo_judy_toArray, ZEND_ACC_PUBLIC)
	PHP_ME(judy, fromArray, 		arginfo_judy_fromArray, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
	PHP_ME(judy, putAll, 			arginfo_judy_putAll, ZEND_ACC_PUBLIC)
	PHP_ME(judy, getAll, 			arginfo_judy_getAll, ZEND_ACC_PUBLIC)
	PHP_ME(judy, increment, 		arginfo_judy_increment, ZEND_ACC_PUBLIC)

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
	ZEND_MOD_REQUIRED("json")
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

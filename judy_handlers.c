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
#include "judy_handlers.h"

/* {{{ judy_object_clone
*/
zend_object *judy_object_clone(zend_object *this_ptr)
{
	judy_object *new_obj = NULL;
	judy_object *old_obj = php_judy_object(this_ptr);

	judy_object_new_ex(old_obj->std.ce, &new_obj);

	/* new Judy array to populate */
	Pvoid_t newJArray = (Pvoid_t) NULL;

	zend_objects_clone_members(&new_obj->std, &old_obj->std);

	if (old_obj->type == TYPE_BITSET) {
		/* Cloning Judy1 Array */

		/* Key/index */
		Word_t  kindex = 0;

		/* Insert return value */
		int     Rc_int = 0;

		J1F(Rc_int, old_obj->array, kindex);
		while (Rc_int == 1)
		{
			J1S(Rc_int, newJArray, kindex);
			J1N(Rc_int, old_obj->array, kindex);
		}
	} else if (old_obj->type == TYPE_INT_TO_INT || old_obj->type == TYPE_INT_TO_MIXED
			|| old_obj->type == TYPE_INT_TO_PACKED) {
		/* Cloning JudyL Array */

		/* Key/index */
		Word_t kindex = 0;

		/* Pointer to the old value */
		Pvoid_t *PValue;

		/* Pointer to the new value */
		Pvoid_t *newPValue;

		JLF(PValue, old_obj->array, kindex);
		while(PValue != NULL && PValue != PJERR)
		{
			JLI(newPValue, newJArray, kindex);
			if (newPValue != NULL && newPValue != PJERR) {
				if (old_obj->type == TYPE_INT_TO_MIXED) {
					zval *value = ecalloc(1, sizeof(zval));
					ZVAL_COPY(value, JUDY_MVAL_READ(PValue));
					JUDY_MVAL_WRITE(newPValue, value);
				} else if (old_obj->type == TYPE_INT_TO_PACKED) {
					judy_packed_value *src = JUDY_PVAL_READ(PValue);
					if (src) {
						judy_packed_value *dst = emalloc(sizeof(judy_packed_value) + src->len);
						dst->len = src->len;
						memcpy(dst->data, src->data, src->len);
						JUDY_PVAL_WRITE(newPValue, dst);
					} else {
						JUDY_PVAL_WRITE(newPValue, NULL);
					}
				} else {
					JUDY_LVAL_WRITE(newPValue, JUDY_LVAL_READ(PValue));
				}
			}
			JLN(PValue, old_obj->array, kindex)
		}
	} else if (old_obj->type == TYPE_STRING_TO_INT || old_obj->type == TYPE_STRING_TO_MIXED) {
		/* Cloning JudySL Array */

		/* Key/index */
		uint8_t kindex[PHP_JUDY_MAX_LENGTH];

		/* Pointer to the old value */
		Pvoid_t *PValue;

		/* Pointer to the new value */
		Pvoid_t *newPValue;

		/* smallest string is a null-terminated character */
		kindex[0] = '\0';

		JSLF(PValue, old_obj->array, kindex);
		while(PValue != NULL && PValue != PJERR)
		{
			JSLI(newPValue, newJArray, kindex);
			if (newPValue != NULL && newPValue != PJERR) {
				if (old_obj->type == TYPE_STRING_TO_MIXED) {
					zval *value = ecalloc(1, sizeof(zval));
					ZVAL_COPY(value, JUDY_MVAL_READ(PValue));
					JUDY_MVAL_WRITE(newPValue, value);
				} else {
					JUDY_LVAL_WRITE(newPValue, JUDY_LVAL_READ(PValue));
				}
			}
			JSLN(PValue, old_obj->array, kindex)
		}
	} else if (old_obj->type == TYPE_STRING_TO_MIXED_HASH) {
		/* Cloning JudyHS Array + parallel JudySL key_index */

		uint8_t kindex[PHP_JUDY_MAX_LENGTH];
		Pvoid_t *KValue;
		Pvoid_t newKeyIndex = (Pvoid_t) NULL;

		kindex[0] = '\0';
		JSLF(KValue, old_obj->key_index, kindex);
		while (KValue != NULL && KValue != PJERR) {
			Word_t klen = (Word_t)strlen((char *)kindex);
			Pvoid_t *HValue;
			JHSG(HValue, old_obj->array, kindex, klen);
			if (HValue != NULL && HValue != PJERR) {
				Pvoid_t *newHValue;
				Pvoid_t *newKValue;
				JHSI(newHValue, newJArray, kindex, klen);
				if (newHValue != NULL && newHValue != PJERR) {
					zval *value = ecalloc(1, sizeof(zval));
					ZVAL_COPY(value, JUDY_MVAL_READ(HValue));
					JUDY_MVAL_WRITE(newHValue, value);
				}
				JSLI(newKValue, newKeyIndex, kindex);
				if (newKValue == PJERR) break;
			}
			JSLN(KValue, old_obj->key_index, kindex)
		}
		new_obj->key_index = newKeyIndex;
	} else if (old_obj->type == TYPE_STRING_TO_INT_HASH) {
		/* Cloning JudyHS Array (Word_t values) + parallel JudySL key_index */

		uint8_t kindex[PHP_JUDY_MAX_LENGTH];
		Pvoid_t *KValue;
		Pvoid_t newKeyIndex = (Pvoid_t) NULL;

		kindex[0] = '\0';
		JSLF(KValue, old_obj->key_index, kindex);
		while (KValue != NULL && KValue != PJERR) {
			Word_t klen = (Word_t)strlen((char *)kindex);
			Pvoid_t *HValue;
			JHSG(HValue, old_obj->array, kindex, klen);
			if (HValue != NULL && HValue != PJERR) {
				Pvoid_t *newHValue;
				Pvoid_t *newKValue;
				JHSI(newHValue, newJArray, kindex, klen);
				if (newHValue != NULL && newHValue != PJERR) {
					JUDY_LVAL_WRITE(newHValue, JUDY_LVAL_READ(HValue));
				}
				JSLI(newKValue, newKeyIndex, kindex);
				if (newKValue == PJERR) break;
			}
			JSLN(KValue, old_obj->key_index, kindex)
		}
		new_obj->key_index = newKeyIndex;
	}

	new_obj->array = newJArray;
	new_obj->type = old_obj->type;
	new_obj->counter = old_obj->counter;
	new_obj->is_integer_keyed = old_obj->is_integer_keyed;
	new_obj->is_string_keyed = old_obj->is_string_keyed;
	new_obj->is_mixed_value = old_obj->is_mixed_value;
	new_obj->is_packed_value = old_obj->is_packed_value;
	new_obj->is_hash_keyed = old_obj->is_hash_keyed;

	return &new_obj->std;
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

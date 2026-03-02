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

#ifndef PHP_JUDY_H
#define PHP_JUDY_H

#define PHP_JUDY_VERSION "2.3.0"
#define PHP_JUDY_EXTNAME "judy"

/* Windows x64 (LLP64): Force 64-bit Word_t to match libjudy ABI.
 *
 * MSVC x64 defines 'unsigned long' as 4 bytes (LLP64 model). Our CI
 * builds libjudy from source with Word_t = unsigned __int64 = 8 bytes.
 * This override ensures our extension uses the same 8-byte Word_t,
 * matching the library's ABI on x64 Windows.
 *
 * Defining _WORD_T before including Judy.h tells it to skip its own
 * Word_t typedef, using ours instead. */
#ifdef _WIN64
#define _WORD_T
typedef unsigned __int64 Word_t, * PWord_t;
#endif

/* Disable default Judy error handler which calls exit(1).
 * With JUDYERROR_NOTEST, Judy macros pass NULL for PJError_t,
 * avoiding JError_t stack allocations (whose size depends on Word_t)
 * and letting us handle errors via return value checks instead. */
#define JUDYERROR_NOTEST 1

#include <Judy.h>

/* Fix PJERR/PPJERR for Windows x64.
 *
 * Judy.h defines PJERR as ((Pvoid_t)(~0UL)). On MSVC x64, unsigned long
 * is 4 bytes (LLP64), so ~0UL = 0xFFFFFFFF, making PJERR a 32-bit sentinel
 * (0x00000000FFFFFFFF) instead of the correct all-ones pointer. This causes
 * error return comparisons to fail. Redefine with ~(size_t)0 to get the
 * correct 64-bit sentinel (0xFFFFFFFFFFFFFFFF). */
#ifdef _WIN64
#undef PJERR
#undef PPJERR
#define PJERR  ((Pvoid_t)(~(size_t)0))
#define PPJERR ((PPvoid_t)(~(size_t)0))
#endif

/* Safe JudyL/JudySL value access macros.
 *
 * JudyL and JudySL store values in Word_t-sized slots internally, but the
 * C API returns PPvoid_t (void**) pointers to these slots. These macros
 * always read/write exactly sizeof(Word_t) bytes, which is correct on all
 * platforms. On Unix LP64 and Windows x64 (with our Word_t override),
 * sizeof(Word_t) == sizeof(void*) == 8, so MIXED types (which store zval*
 * pointers in value slots) work correctly everywhere. */
#define JUDY_LVAL_READ(PV)       ((zend_long)(*(Word_t *)(PV)))
#define JUDY_LVAL_WRITE(PV, v)   (*(Word_t *)(PV) = (Word_t)(v))

#if defined(__GNUC__) || defined(__clang__)
#define JUDY_LIKELY(x)   __builtin_expect(!!(x), 1)
#define JUDY_UNLIKELY(x) __builtin_expect(!!(x), 0)
#else
#define JUDY_LIKELY(x)   (x)
#define JUDY_UNLIKELY(x) (x)
#endif

#define JUDY_MIXED_SUPPORTED 1
#define JUDY_MVAL_READ(PV)       ((zval *)(*(PV)))
#define JUDY_MVAL_WRITE(PV, p)   (*(PV) = (Pvoid_t)(p))

#define JUDY_PVAL_READ(PV)      ((judy_packed_value *)(*(PV)))
#define JUDY_PVAL_WRITE(PV, p)  (*(PV) = (Pvoid_t)(p))

#include "php.h"
#include "php_ini.h"
#include "zend_exceptions.h"
#include "zend_interfaces.h"
#include "ext/standard/info.h"

/* Packed value storage for TYPE_INT_TO_PACKED.
 * Tagged union: scalars stored directly (no serialize), complex types fall back. */
typedef enum _judy_packed_tag {
    JUDY_TAG_LONG       = 0,
    JUDY_TAG_DOUBLE     = 1,
    JUDY_TAG_TRUE       = 2,
    JUDY_TAG_FALSE      = 3,
    JUDY_TAG_NULL       = 4,
    JUDY_TAG_STRING     = 5,
    JUDY_TAG_SERIALIZED = 255
} judy_packed_tag;

typedef struct _judy_packed_value {
    uint8_t tag;
    union {
        zend_long lval;
        double    dval;
        struct { size_t len; char data[]; } str;
    } v;
} judy_packed_value;

static inline size_t judy_packed_value_size(judy_packed_value *p) {
    switch ((judy_packed_tag)p->tag) {
    case JUDY_TAG_LONG:       return offsetof(judy_packed_value, v) + sizeof(zend_long);
    case JUDY_TAG_DOUBLE:     return offsetof(judy_packed_value, v) + sizeof(double);
    case JUDY_TAG_TRUE:
    case JUDY_TAG_FALSE:
    case JUDY_TAG_NULL:       return offsetof(judy_packed_value, v);
    case JUDY_TAG_STRING:
    case JUDY_TAG_SERIALIZED: return offsetof(judy_packed_value, v) + sizeof(size_t) + p->v.str.len;
    default:
        zend_error(E_WARNING, "judy_packed_value: unknown tag %u", (unsigned)p->tag);
        return 0;
    }
}

extern zend_module_entry judy_module_entry;
#define phpext_judy_ptr &judy_module_entry

#ifdef PHP_WIN32
#    define PHP_JUDY_API __declspec(dllexport)
#elif defined(__GNUC__) && __GNUC__ >= 4
#    define PHP_JUDY_API __attribute__ ((visibility("default")))
#else
#    define PHP_JUDY_API
#endif

#ifdef ZTS
#include "TSRM.h"
#endif

extern const zend_function_entry judy_class_methods[];

/* {{{ judy_type
 internal Judy Array type (aka Judy1, JudyL and JudySL) */
typedef enum _judy_type {
    TYPE_BITSET=1,
    TYPE_INT_TO_INT,
    TYPE_INT_TO_MIXED,
    TYPE_STRING_TO_INT,
    TYPE_STRING_TO_MIXED,
    TYPE_INT_TO_PACKED,
    TYPE_STRING_TO_MIXED_HASH, /* JudyHS: O(1) avg hash lookup, parallel JudySL key index for iteration */
    TYPE_STRING_TO_INT_HASH,   /* JudyHS: O(1) avg hash lookup for string→int, parallel JudySL key index */
    TYPE_STRING_TO_MIXED_ADAPTIVE, /* SSO: JudyL for <8 bytes, JudyHS for longer + parallel JudySL */
    TYPE_STRING_TO_INT_ADAPTIVE    /* SSO for string→int */
} judy_type;
/* }}} */

#define JTYPE(jtype, type) { \
    if (type != TYPE_BITSET && type != TYPE_INT_TO_INT \
                           && type != TYPE_INT_TO_MIXED \
                           && type != TYPE_STRING_TO_INT \
                           && type != TYPE_STRING_TO_MIXED \
                           && type != TYPE_INT_TO_PACKED \
                           && type != TYPE_STRING_TO_MIXED_HASH \
                           && type != TYPE_STRING_TO_INT_HASH \
                           && type != TYPE_STRING_TO_MIXED_ADAPTIVE \
                           && type != TYPE_STRING_TO_INT_ADAPTIVE) { \
        php_error_docref(NULL, E_WARNING, "Not a valid Judy type. Please check the documentation for valid Judy type constant."); \
        jtype = 0; \
    } else { \
        jtype = type; \
    } \
}

#define JUDY_METHOD_ERROR_HANDLING \
    zend_error_handling error_handling; \
    zend_replace_error_handling(EH_THROW, NULL, &error_handling);

#define JUDY_METHOD_GET_OBJECT \
    zval *object = getThis(); \
    judy_object *intern = php_judy_object(Z_OBJ_P(object));

/* Performance optimization macros using cached type flags */
#define JUDY_IS_INTEGER_KEYED(intern) ((intern)->is_integer_keyed)
#define JUDY_IS_STRING_KEYED(intern) ((intern)->is_string_keyed)
#define JUDY_IS_MIXED_VALUE(intern) ((intern)->is_mixed_value)
#define JUDY_IS_PACKED_VALUE(intern) ((intern)->is_packed_value)
#define JUDY_IS_ADAPTIVE(intern) ((intern)->is_adaptive)

typedef struct _judy_object judy_object;

typedef struct _judy_type_ops {
	zval *(*read)(judy_object *intern, zval *offset, zval *rv);
	int   (*write)(judy_object *intern, zval *offset, zval *value);
	int   (*has)(judy_object *intern, zval *offset, int check_empty);
	int   (*unset)(judy_object *intern, zval *offset);
} judy_type_ops;

typedef struct _judy_kv {
	Word_t key;
	Word_t value;
} judy_kv;

struct _judy_object {
	Pvoid_t         array;               /* 8 — hottest field */
	Pvoid_t         key_index;           /* 8 */
	Pvoid_t         hs_array;            /* 8 — for longer strings in ADAPTIVE type */
	const judy_type_ops *ops;            /* 8 — vtable for Tiered Storage (2C) */
	judy_kv         *linear_data;        /* 8 — Tier 1: Sorted Linear Array (6A) */
	zend_long       counter;             /* 8 */
	Word_t			next_empty;          /* 8 */
	zend_long       type;                /* 8 */
	/* Tier 0: Inline Storage (6A) - 8 KV pairs */
	Word_t          inline_keys[8];
	Word_t          inline_values[8];
	/* Iterator state for Iterator interface methods */
	zval            iterator_key;        /* 16 */
	zval            iterator_data;       /* 16 */
	uint8_t         *key_scratch;        /* 8 — heap-allocated PHP_JUDY_MAX_LENGTH buffer */
	/* Pack all bools together (8 bytes) */
	uint8_t         storage_tier;        /* 0: Inline, 1: Linear, 2: Judy */
	zend_bool       next_empty_is_valid;
	zend_bool       iterator_initialized;
	zend_bool       is_integer_keyed;
	zend_bool       is_string_keyed;
	zend_bool       is_mixed_value;
	zend_bool       is_packed_value;
	zend_bool       is_hash_keyed;
	zend_bool       is_adaptive;
	zend_object     std;                 /* must be last */
};

static inline judy_object *php_judy_object(zend_object *obj) {
	return (judy_object *)((char*)(obj) - XtOffsetOf(judy_object, std));
}

static inline int judy_pack_short_string_internal(const char *str, size_t len, Word_t *index)
{
	if (len >= 8) return 0;
	*index = 0;
	memcpy(index, str, len);
	return 1;
}

/* Max length, this must be a constant for it to work in
 * declarings as we cannot use runtime decided values at
 * compile time ofcourse
 *
 * TODO:	This needs to be handled better
 */
#define PHP_JUDY_MAX_LENGTH 65536

zend_object *judy_object_new(zend_class_entry *ce);
zend_object *judy_object_new_ex(zend_class_entry *ce, judy_object **ptr);

zval *judy_object_read_dimension_helper(zval *object, zval *offset, zval *rv);
int judy_object_write_dimension_helper(zval *object, zval *offset, zval *value);
int judy_object_has_dimension_helper(zval *object, zval *offset, int check_empty);
int judy_object_unset_dimension_helper(zval *object, zval *offset);

judy_packed_value *judy_pack_value(zval *value);
int judy_unpack_value(judy_packed_value *packed, zval *rv);

/* {{{ REGISTER_JUDY_CLASS_CONST_LONG */
#define REGISTER_JUDY_CLASS_CONST_LONG(const_name, value) \
    zend_declare_class_constant_long(judy_ce, const_name, sizeof(const_name)-1, (zend_long) value);
/* }}} */

ZEND_BEGIN_MODULE_GLOBALS(judy)
    unsigned long    max_length;
ZEND_END_MODULE_GLOBALS(judy)

ZEND_EXTERN_MODULE_GLOBALS(judy)

#ifdef ZTS
#define JUDY_G(v) TSRMG(judy_globals_id, zend_judy_globals *, v)
#else
#define JUDY_G(v) (judy_globals.v)
#endif

/* Grabbing CE's so that other exts can use the date objects too */
PHP_JUDY_API zend_class_entry *php_judy_ce(void);

#endif    /* PHP_JUDY_H */


/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * End:
 * vim600: noet sw=4 ts=4 fdm=marker
 * vim<600: noet sw=4 ts=4
 */

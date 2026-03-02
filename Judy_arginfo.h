/* This is a generated file, edit the .stub.php file instead.
 * Stub hash: e1cf1a00811b2ae2bd9502cb30fe573f640ecf73 */

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_judy_version, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_judy_type, 0, 1, IS_LONG, 0)
	ZEND_ARG_INFO(0, array)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_Judy___construct, 0, 0, 1)
	ZEND_ARG_TYPE_INFO(0, type, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_Judy___destruct, 0, 0, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Judy_getType, 0, 0, IS_LONG, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Judy_free arginfo_class_Judy_getType

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Judy_memoryUsage, 0, 0, IS_LONG, 1)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Judy_size, 0, 0, IS_LONG, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, index_start, IS_MIXED, 0, "0")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, index_end, IS_MIXED, 0, "-1")
ZEND_END_ARG_INFO()

#define arginfo_class_Judy_count arginfo_class_Judy_getType

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Judy_byCount, 0, 1, IS_MIXED, 0)
	ZEND_ARG_TYPE_INFO(0, nth_index, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Judy_first, 0, 0, IS_MIXED, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, index, IS_MIXED, 0, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Judy_searchNext, 0, 1, IS_MIXED, 0)
	ZEND_ARG_TYPE_INFO(0, index, IS_MIXED, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Judy_last arginfo_class_Judy_first

#define arginfo_class_Judy_prev arginfo_class_Judy_searchNext

#define arginfo_class_Judy_firstEmpty arginfo_class_Judy_first

#define arginfo_class_Judy_nextEmpty arginfo_class_Judy_searchNext

#define arginfo_class_Judy_lastEmpty arginfo_class_Judy_first

#define arginfo_class_Judy_prevEmpty arginfo_class_Judy_searchNext

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_Judy_union, 0, 1, Judy, 0)
	ZEND_ARG_OBJ_INFO(0, other, Judy, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Judy_intersect arginfo_class_Judy_union

#define arginfo_class_Judy_diff arginfo_class_Judy_union

#define arginfo_class_Judy_xor arginfo_class_Judy_union

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_Judy_slice, 0, 2, Judy, 0)
	ZEND_ARG_TYPE_INFO(0, start, IS_MIXED, 0)
	ZEND_ARG_TYPE_INFO(0, end, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Judy_offsetExists, 0, 1, _IS_BOOL, 0)
	ZEND_ARG_TYPE_INFO(0, offset, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Judy_offsetGet, 0, 1, IS_MIXED, 0)
	ZEND_ARG_TYPE_INFO(0, offset, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Judy_offsetSet, 0, 2, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, offset, IS_MIXED, 0)
	ZEND_ARG_TYPE_INFO(0, value, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Judy_offsetUnset, 0, 1, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, offset, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Judy_jsonSerialize, 0, 0, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Judy___serialize, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Judy___unserialize, 0, 1, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, data, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Judy_toArray arginfo_class_Judy___serialize

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_Judy_fromArray, 0, 2, Judy, 0)
	ZEND_ARG_TYPE_INFO(0, type, IS_LONG, 0)
	ZEND_ARG_TYPE_INFO(0, data, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Judy_putAll arginfo_class_Judy___unserialize

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Judy_getAll, 0, 1, IS_ARRAY, 0)
	ZEND_ARG_TYPE_INFO(0, keys, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Judy_increment, 0, 1, IS_LONG, 0)
	ZEND_ARG_TYPE_INFO(0, key, IS_MIXED, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, amount, IS_LONG, 0, "1")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Judy_rewind, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Judy_valid, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Judy_current arginfo_class_Judy_jsonSerialize

#define arginfo_class_Judy_key arginfo_class_Judy_jsonSerialize

#define arginfo_class_Judy_next arginfo_class_Judy_rewind

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Judy_keys, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_Judy_values arginfo_class_Judy_keys

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_MASK_EX(arginfo_class_Judy_sumValues, 0, MAY_BE_LONG|MAY_BE_DOUBLE)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Judy_populationCount, 0, 0, IS_LONG, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, start, IS_MIXED, 0, "0")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, end, IS_MIXED, 0, "-1")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Judy_deleteRange, 0, 2, IS_LONG, 0)
	ZEND_ARG_TYPE_INFO(0, start, IS_MIXED, 0)
	ZEND_ARG_TYPE_INFO(0, end, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_Judy_equals, 0, 1, _IS_BOOL, 0)
	ZEND_ARG_OBJ_INFO(0, other, Judy, 0)
ZEND_END_ARG_INFO()


ZEND_FUNCTION(judy_version);
ZEND_FUNCTION(judy_type);
ZEND_METHOD(Judy, __construct);
ZEND_METHOD(Judy, __destruct);
ZEND_METHOD(Judy, getType);
ZEND_METHOD(Judy, free);
ZEND_METHOD(Judy, memoryUsage);
ZEND_METHOD(Judy, size);
ZEND_METHOD(Judy, count);
ZEND_METHOD(Judy, byCount);
ZEND_METHOD(Judy, first);
ZEND_METHOD(Judy, searchNext);
ZEND_METHOD(Judy, last);
ZEND_METHOD(Judy, prev);
ZEND_METHOD(Judy, firstEmpty);
ZEND_METHOD(Judy, nextEmpty);
ZEND_METHOD(Judy, lastEmpty);
ZEND_METHOD(Judy, prevEmpty);
ZEND_METHOD(Judy, union);
ZEND_METHOD(Judy, intersect);
ZEND_METHOD(Judy, diff);
ZEND_METHOD(Judy, xor);
ZEND_METHOD(Judy, slice);
ZEND_METHOD(Judy, offsetExists);
ZEND_METHOD(Judy, offsetGet);
ZEND_METHOD(Judy, offsetSet);
ZEND_METHOD(Judy, offsetUnset);
ZEND_METHOD(Judy, jsonSerialize);
ZEND_METHOD(Judy, __serialize);
ZEND_METHOD(Judy, __unserialize);
ZEND_METHOD(Judy, toArray);
ZEND_METHOD(Judy, fromArray);
ZEND_METHOD(Judy, putAll);
ZEND_METHOD(Judy, getAll);
ZEND_METHOD(Judy, increment);
ZEND_METHOD(Judy, rewind);
ZEND_METHOD(Judy, valid);
ZEND_METHOD(Judy, current);
ZEND_METHOD(Judy, key);
ZEND_METHOD(Judy, next);
ZEND_METHOD(Judy, keys);
ZEND_METHOD(Judy, values);
ZEND_METHOD(Judy, sumValues);
ZEND_METHOD(Judy, populationCount);
ZEND_METHOD(Judy, deleteRange);
ZEND_METHOD(Judy, equals);


static const zend_function_entry ext_functions[] = {
	ZEND_FE(judy_version, arginfo_judy_version)
	ZEND_FE(judy_type, arginfo_judy_type)
	ZEND_FE_END
};


static const zend_function_entry class_Judy_methods[] = {
	ZEND_ME(Judy, __construct, arginfo_class_Judy___construct, ZEND_ACC_PUBLIC)
	ZEND_ME(Judy, __destruct, arginfo_class_Judy___destruct, ZEND_ACC_PUBLIC)
	ZEND_ME(Judy, getType, arginfo_class_Judy_getType, ZEND_ACC_PUBLIC)
	ZEND_ME(Judy, free, arginfo_class_Judy_free, ZEND_ACC_PUBLIC)
	ZEND_ME(Judy, memoryUsage, arginfo_class_Judy_memoryUsage, ZEND_ACC_PUBLIC)
	ZEND_ME(Judy, size, arginfo_class_Judy_size, ZEND_ACC_PUBLIC)
	ZEND_ME(Judy, count, arginfo_class_Judy_count, ZEND_ACC_PUBLIC)
	ZEND_ME(Judy, byCount, arginfo_class_Judy_byCount, ZEND_ACC_PUBLIC)
	ZEND_ME(Judy, first, arginfo_class_Judy_first, ZEND_ACC_PUBLIC)
	ZEND_ME(Judy, searchNext, arginfo_class_Judy_searchNext, ZEND_ACC_PUBLIC)
	ZEND_ME(Judy, last, arginfo_class_Judy_last, ZEND_ACC_PUBLIC)
	ZEND_ME(Judy, prev, arginfo_class_Judy_prev, ZEND_ACC_PUBLIC)
	ZEND_ME(Judy, firstEmpty, arginfo_class_Judy_firstEmpty, ZEND_ACC_PUBLIC)
	ZEND_ME(Judy, nextEmpty, arginfo_class_Judy_nextEmpty, ZEND_ACC_PUBLIC)
	ZEND_ME(Judy, lastEmpty, arginfo_class_Judy_lastEmpty, ZEND_ACC_PUBLIC)
	ZEND_ME(Judy, prevEmpty, arginfo_class_Judy_prevEmpty, ZEND_ACC_PUBLIC)
	ZEND_ME(Judy, union, arginfo_class_Judy_union, ZEND_ACC_PUBLIC)
	ZEND_ME(Judy, intersect, arginfo_class_Judy_intersect, ZEND_ACC_PUBLIC)
	ZEND_ME(Judy, diff, arginfo_class_Judy_diff, ZEND_ACC_PUBLIC)
	ZEND_ME(Judy, xor, arginfo_class_Judy_xor, ZEND_ACC_PUBLIC)
	ZEND_ME(Judy, slice, arginfo_class_Judy_slice, ZEND_ACC_PUBLIC)
	ZEND_ME(Judy, offsetExists, arginfo_class_Judy_offsetExists, ZEND_ACC_PUBLIC)
	ZEND_ME(Judy, offsetGet, arginfo_class_Judy_offsetGet, ZEND_ACC_PUBLIC)
	ZEND_ME(Judy, offsetSet, arginfo_class_Judy_offsetSet, ZEND_ACC_PUBLIC)
	ZEND_ME(Judy, offsetUnset, arginfo_class_Judy_offsetUnset, ZEND_ACC_PUBLIC)
	ZEND_ME(Judy, jsonSerialize, arginfo_class_Judy_jsonSerialize, ZEND_ACC_PUBLIC)
	ZEND_ME(Judy, __serialize, arginfo_class_Judy___serialize, ZEND_ACC_PUBLIC)
	ZEND_ME(Judy, __unserialize, arginfo_class_Judy___unserialize, ZEND_ACC_PUBLIC)
	ZEND_ME(Judy, toArray, arginfo_class_Judy_toArray, ZEND_ACC_PUBLIC)
	ZEND_ME(Judy, fromArray, arginfo_class_Judy_fromArray, ZEND_ACC_PUBLIC|ZEND_ACC_STATIC)
	ZEND_ME(Judy, putAll, arginfo_class_Judy_putAll, ZEND_ACC_PUBLIC)
	ZEND_ME(Judy, getAll, arginfo_class_Judy_getAll, ZEND_ACC_PUBLIC)
	ZEND_ME(Judy, increment, arginfo_class_Judy_increment, ZEND_ACC_PUBLIC)
	ZEND_ME(Judy, rewind, arginfo_class_Judy_rewind, ZEND_ACC_PUBLIC)
	ZEND_ME(Judy, valid, arginfo_class_Judy_valid, ZEND_ACC_PUBLIC)
	ZEND_ME(Judy, current, arginfo_class_Judy_current, ZEND_ACC_PUBLIC)
	ZEND_ME(Judy, key, arginfo_class_Judy_key, ZEND_ACC_PUBLIC)
	ZEND_ME(Judy, next, arginfo_class_Judy_next, ZEND_ACC_PUBLIC)
	ZEND_ME(Judy, keys, arginfo_class_Judy_keys, ZEND_ACC_PUBLIC)
	ZEND_ME(Judy, values, arginfo_class_Judy_values, ZEND_ACC_PUBLIC)
	ZEND_ME(Judy, sumValues, arginfo_class_Judy_sumValues, ZEND_ACC_PUBLIC)
	ZEND_ME(Judy, populationCount, arginfo_class_Judy_populationCount, ZEND_ACC_PUBLIC)
	ZEND_ME(Judy, deleteRange, arginfo_class_Judy_deleteRange, ZEND_ACC_PUBLIC)
	ZEND_ME(Judy, equals, arginfo_class_Judy_equals, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};

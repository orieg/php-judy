# php-judy lldb pretty-printers — for contributors debugging the EXTENSION.
#
# This is the C-side companion to the `get_debug_info` handler. That handler is
# what a PHP user sees (var_dump(), Xdebug, the IDE variable pane): type name,
# count, memoryUsage, first/last key, a bounded preview. This file is for the
# other audience — someone in lldb on a crash, a core dump or a valgrind run,
# looking at the raw `judy_object` struct, where the type is a bare integer, the
# storage roots are opaque `Pvoid_t`, and the flag block is a row of bitfields.
#
#   LOADING
#
#       (lldb) command script import scripts/judy_lldb.py
#
#   or permanently, in ~/.lldbinit (absolute path required):
#
#       command script import /path/to/php-judy/scripts/judy_lldb.py
#
#   It imports scripts/judy_debug_common.py from the same directory, which
#   holds everything php-judy-specific and is shared with scripts/judy_gdb.py.
#
#   BUILD REQUIREMENT — the default build is NOT debuggable.
#
#   `phpize && ./configure && make` inherits PHP's own CFLAGS, which on a
#   typical distro or Homebrew PHP include `-O3 -DNDEBUG -flto`. Under `-flto`
#   clang emits a single debug-map entry pointing at a temporary `/tmp/lto.o`
#   that is gone by the time you debug, so lldb has NO type information and NO
#   locals for the extension: a breakpoint hits and `frame variable intern`
#   finds nothing. Rebuild with:
#
#       make clean && make EXTRA_CFLAGS="-g -O0 -fno-lto"
#
#   (EXTRA_CFLAGS lands after CFLAGS on the compile line, so it wins.) Confirm
#   with `nm -pa modules/judy.so | grep OSO` — you want one entry per .o under
#   .libs/, not a single /tmp/lto.o.
#
#   WHAT YOU GET
#
#   Automatic summaries, so plain `frame variable` / `p` are readable:
#
#       judy_object, judy_object *   one-line digest: type name, count, flags
#       judy_iterator                foreach-iterator cursor state
#       judy_packed_value            INT_TO_PACKED tagged union, decoded
#
#   And one command for the full breakdown:
#
#       (lldb) judy                  # defaults to `intern` in the current frame
#       (lldb) judy intern
#       (lldb) judy object           # a zend_object* is accepted and rebased
#       (lldb) judy it->intern.data  # any variable path
#
#   The type NAMES come from the `judy_type` enum in the debug info rather than
#   from a table here, so they cannot drift from judy_type_name() in php_judy.c.
#   judy_debug_common.TYPE_FALLBACK is only used when that enum is missing.
#
#   WHAT IT DELIBERATELY DOES NOT DO — walk the Judy tree.
#
#   libJudy's node layout is internal, undocumented and version-dependent: the
#   root word is a tagged pointer whose meaning depends on the population and
#   on compile-time constants of the libJudy you linked against. A printer that
#   decoded it by guesswork would be confidently wrong on the next libJudy, and
#   a wrong element listing is worse than none when you are already debugging a
#   corruption. So the storage roots are printed as pointers, annotated with
#   which libJudy flavour (Judy1/JudyL/JudySL/JudyHS) each one is for this type,
#   and the population is read from `intern->counter`, which the extension
#   maintains itself. To see elements, use the PHP side: break somewhere you can
#   run code and call `var_dump()`, or drive the array from a .phpt.
#
#   No expression evaluation is used for any of the rendering — every field is a
#   direct memory read through the SB API — so the printers work in a core dump
#   and at a breakpoint in a signal handler, where running code in the inferior
#   would fail or lie.
#
#   ONE LLDB GOTCHA, not the printers' fault: a breakpoint set by function name
#   stops at the function's first line, BEFORE its locals are assigned, so
#   `intern` there is uninitialised garbage. Set the breakpoint a line or two
#   later (`breakpoint set -f php_judy.c -l <line>`), or `next` once first.
#
#   The Python files here are named with underscores, not the dashes used by the
#   PHP scripts beside them, because the debugger imports them as Python modules
#   and a module name has to be a valid identifier.

import os
import sys

import lldb

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import judy_debug_common as jc  # noqa: E402


# ---------------------------------------------------------------------------
# judy_type -> name, from the debug info where possible.
# ---------------------------------------------------------------------------

_type_table_cache = {}


def _type_table(target):
    exe = target.GetExecutable()
    key = (exe.fullpath if exe and exe.IsValid() else None) or "<no-exe>"
    cached = _type_table_cache.get(key)
    if cached is not None:
        return cached

    table, source = None, None
    for type_name in ("judy_type", "enum _judy_type", "_judy_type"):
        t = target.FindFirstType(type_name)
        if not t or not t.IsValid():
            continue
        members = t.GetEnumMembers()
        if not members or members.GetSize() == 0:
            continue
        table = {}
        for i in range(members.GetSize()):
            m = members.GetTypeEnumMemberAtIndex(i)
            name = m.GetName() or ""
            if name.startswith("TYPE_"):
                name = name[len("TYPE_"):]
            table[m.GetValueAsSigned()] = name
        source = "debug info (enum judy_type)"
        break

    if table is None:
        table, source = dict(jc.TYPE_FALLBACK), "built-in fallback table"

    _type_table_cache[key] = (table, source)
    return table, source


# ---------------------------------------------------------------------------
# SB helpers. Everything is a memory read; nothing runs in the inferior, so it
# all works on a core dump.
# ---------------------------------------------------------------------------

def _member(v, *path):
    for name in path:
        if not v or not v.IsValid():
            return None
        v = v.GetChildMemberWithName(name)
    return v if (v and v.IsValid()) else None


def _u(v, *path):
    m = _member(v, *path)
    return None if m is None else m.GetValueAsUnsigned()


def _s(v, *path):
    m = _member(v, *path)
    return None if m is None else m.GetValueAsSigned()


def _read(value, addr, size):
    if not addr or addr == lldb.LLDB_INVALID_ADDRESS or size <= 0:
        return None
    process = value.GetProcess()
    if not process or not process.IsValid():
        return None
    err = lldb.SBError()
    data = process.ReadMemory(addr, size, err)
    return data if err.Success() else None


def _read_cstr(value, addr, limit=96):
    """NUL-terminated bytes at addr, quoted. None if unreadable."""
    data = _read(value, addr, limit)
    if data is None:
        # A short read near the end of a mapping is normal; retry smaller.
        data = _read(value, addr, 16)
        if data is None:
            return None
    nul = data.find(b"\x00")
    truncated = nul < 0
    if not truncated:
        data = data[:nul]
    return jc.quote_bytes(data, truncated)


def _zval_str(z):
    """Render a zval by hand — the engine's own printers are not loaded here."""
    if z is None or not z.IsValid():
        return "<unreadable>"
    tag = _u(z, "u1", "v", "type")
    if tag is None:
        return "<no zval type info>"
    if tag == 4:
        return "LONG %d" % (_s(z, "value", "lval") or 0)
    if tag == 5:
        d = _member(z, "value", "dval")
        return "DOUBLE %s" % (d.GetValue() if d else "?")
    if tag == 6:
        sp = _member(z, "value", "str")
        if sp is None or sp.GetValueAsUnsigned() == 0:
            return "STRING <null zend_string*>"
        zs = sp.Dereference()
        ln = _u(zs, "len")
        val = _member(zs, "val")
        if ln is None or val is None:
            return "STRING @0x%x <no zend_string type info>" % sp.GetValueAsUnsigned()
        show = min(ln, 96)
        raw = _read(z, val.GetLoadAddress(), show) if show else b""
        if raw is None:
            return "STRING(len=%d) <unreadable>" % ln
        return "STRING(len=%d) %s" % (ln, jc.quote_bytes(raw, ln > show))
    return jc.ZVAL_TYPES.get(tag, "type#%d" % tag)


# ---------------------------------------------------------------------------
# Getting to a judy_object from whatever the user has in hand.
# ---------------------------------------------------------------------------

def _as_judy_object(v):
    """Returns (SBValue of judy_object, note) or (None, reason)."""
    if v is None or not v.IsValid():
        return None, "not a valid value"

    t = v.GetType()
    if t.IsReferenceType():
        v = v.Dereference()
        t = v.GetType()

    name = (t.GetName() or "").replace("const ", "").strip()

    if name in ("judy_object *", "struct _judy_object *"):
        if v.GetValueAsUnsigned() == 0:
            return None, "judy_object* is NULL"
        return v.Dereference(), None
    if name in ("judy_object", "struct _judy_object"):
        return v, None

    if name in ("zend_object *", "struct _zend_object *"):
        if v.GetValueAsUnsigned() == 0:
            return None, "zend_object* is NULL"
        return _rebase_zend_object(v, v.GetValueAsUnsigned())
    if name in ("zend_object", "struct _zend_object"):
        return _rebase_zend_object(v, v.GetLoadAddress())

    if name in ("zval", "struct _zval_struct", "zval *", "struct _zval_struct *"):
        z = v.Dereference() if name.endswith("*") else v
        obj = _member(z, "value", "obj")
        if obj is not None and obj.GetValueAsUnsigned():
            return _rebase_zend_object(obj, obj.GetValueAsUnsigned())
        return None, "zval does not hold an object"

    return None, "don't know how to read a judy_object out of type '%s'" % name


def _rebase_zend_object(v, addr):
    """php_judy_object(): back up from the embedded ->std to the container."""
    if addr in (0, lldb.LLDB_INVALID_ADDRESS):
        return None, "zend_object address unavailable"
    target = v.GetTarget()
    jo = target.FindFirstType("judy_object")
    if not jo or not jo.IsValid():
        return None, "no 'judy_object' type in the debug info.\n" + jc.NO_DEBUG_INFO_HINT
    offset = None
    for i in range(jo.GetNumberOfFields()):
        f = jo.GetFieldAtIndex(i)
        if f.GetName() == "std":
            offset = f.GetOffsetInBytes()
            break
    if offset is None:
        return None, "judy_object has no 'std' field?"
    base = addr - offset
    out = target.CreateValueFromAddress("judy", lldb.SBAddress(base, target), jo)
    if not out or not out.IsValid():
        return None, "could not materialise judy_object at 0x%x" % base
    return out, ("rebased from zend_object 0x%x (offsetof(judy_object, std) = %d)"
                 % (addr, offset))


def _collect(jo, note=None):
    """Read every field the report needs into the plain dict jc expects."""
    table, source = _type_table(jo.GetTarget())
    addr = jo.GetLoadAddress()
    ks = _u(jo, "key_scratch")
    return {
        "addr": None if addr == lldb.LLDB_INVALID_ADDRESS else addr,
        "note": note,
        "raw_type": _s(jo, "type"),
        "type_table": table,
        "type_source": source,
        "counter": _s(jo, "counter"),
        "approx_payload": _s(jo, "approx_payload_bytes"),
        "flags": dict((n, _u(jo, n)) for n in jc.ALL_FLAGS),
        "roots": dict((n, _u(jo, n)) for n in jc.ROOT_FIELDS),
        "iterator_key": _zval_str(_member(jo, "iterator_key")),
        "iterator_data": _zval_str(_member(jo, "iterator_data")),
        "next_empty": _u(jo, "next_empty"),
        "key_scratch": ks,
        "key_scratch_text": _read_cstr(jo, ks) if ks else None,
    }


# ---------------------------------------------------------------------------
# Summaries.
# ---------------------------------------------------------------------------

def judy_object_summary(valobj, internal_dict, options=None):
    jo, err = _as_judy_object(valobj)
    if jo is None:
        return "<%s>" % err.splitlines()[0]
    return jc.summary_line(_collect(jo))


def judy_iterator_summary(valobj, internal_dict, options=None):
    v = valobj.Dereference() if valobj.GetType().IsPointerType() else valobj
    valid = _u(v, "valid")
    return "judy_iterator valid=%s key=%s data=%s" % (
        "?" if valid is None else valid,
        _zval_str(_member(v, "key")), _zval_str(_member(v, "data")))


def judy_packed_value_summary(valobj, internal_dict, options=None):
    v = valobj.Dereference() if valobj.GetType().IsPointerType() else valobj
    tag = _u(v, "tag")
    if tag is None:
        return "<unreadable judy_packed_value>"
    name = jc.PACKED_TAGS.get(tag, "UNKNOWN(%d)" % tag)
    if tag == 0:
        return "packed LONG %d" % (_s(v, "v", "lval") or 0)
    if tag == 1:
        d = _member(v, "v", "dval")
        return "packed DOUBLE %s" % (d.GetValue() if d else "?")
    if tag in (5, 255):
        ln = _u(v, "v", "str", "len")
        blob = _member(v, "v", "str", "data")
        text = ""
        if blob is not None and ln:
            show = min(ln, 64)
            raw = _read(v, blob.GetLoadAddress(), show)
            if raw is not None:
                text = " " + jc.quote_bytes(raw, ln > show)
        return "packed %s(len=%s)%s" % (name, ln, text)
    return "packed %s" % name


# ---------------------------------------------------------------------------
# `judy` command.
# ---------------------------------------------------------------------------

def _debug_info_hint(frame):
    target = frame.GetThread().GetProcess().GetTarget()
    t = target.FindFirstType("judy_object")
    return "" if (t and t.IsValid()) else "\n\n" + jc.NO_DEBUG_INFO_HINT


def _resolve(frame, expr):
    if expr:
        v = frame.GetValueForVariablePath(expr)
        if v and v.IsValid():
            return v, None
        v = frame.EvaluateExpression(expr)
        if v and v.IsValid() and v.GetError().Success():
            return v, None
        return None, ("cannot resolve '%s' in this frame" % expr) + _debug_info_hint(frame)
    for name in ("intern", "object", "obj", "result"):
        v = frame.FindVariable(name)
        if v and v.IsValid():
            return v, "(no argument given; using `%s` from this frame)" % name
    return None, ("no argument given and no `intern`/`object`/`obj` in this frame "
                  "— pass an expression, e.g. `judy intern`" + _debug_info_hint(frame))


def cmd_judy(debugger, command, exe_ctx, result, internal_dict):
    expr = command.strip()
    if expr in ("-h", "--help", "help"):
        result.AppendMessage(jc.HELP)
        return

    frame = exe_ctx.GetFrame()
    if not frame or not frame.IsValid():
        result.SetError("no frame — select one first (`thread select`, `frame select`)")
        return

    v, note = _resolve(frame, expr)
    if v is None:
        result.SetError(note)
        return

    jo, err = _as_judy_object(v)
    if jo is None:
        result.SetError(err)
        return
    if err:  # a note, not a failure
        note = ((note + " ") if note else "") + err

    result.AppendMessage("\n".join(jc.report_lines(_collect(jo, note))))


def __lldb_init_module(debugger, internal_dict):
    mod = __name__
    for func, specs in (
        ("judy_object_summary", ("judy_object", "judy_object *")),
        ("judy_iterator_summary", ("judy_iterator", "judy_iterator *")),
        ("judy_packed_value_summary", ("judy_packed_value", "judy_packed_value *")),
    ):
        for spec in specs:
            debugger.HandleCommand(
                'type summary add -F %s.%s "%s"' % (mod, func, spec))
    debugger.HandleCommand('command script add -o -f %s.cmd_judy judy' % mod)
    print("php-judy: summaries for judy_object / judy_iterator / "
          "judy_packed_value installed; `judy` command available (`judy --help`).")

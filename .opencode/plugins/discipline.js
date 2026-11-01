// Written by `discipline hook install --agent opencode`.
// discipline-hook-file: mode=observe sha256=7455debabe1fd05598196ab6a956e551713965a4483d70a16d45a30fca073b48
// When a session is created, takes this worktree's lease for it. Before an edit tool, refuses an edit outside this session's worktree (the tool call
// is sent on stdin; a refusal throws, and the model reads the reason). After it, runs
// the discipline check and, when it fails, appends the report to the tool's output so
// the model reads it and repairs the change. On OpenCode 2.x, at the end of a turn, sends
// the final message to the end-of-turn checks; a refusal goes back to the model.
// Observe mode: no hook below refuses a tool call or throws, whatever the command does (a non-zero exit, a command that is not found, any other error).
import { $ } from "bun"

const EDIT_TOOLS = ["edit", "write", "apply_patch"]
// OpenCode 2.x names its shell tool `shell`; 1.x named it `bash`.
const SHELL_TOOLS = ["bash", "shell"]
// 1.x: a shell call's arguments, kept from `tool.execute.before` for `tool.execute.after`.
const SHELL_ARGS = new Map()
const HOOK_TIMEOUT_MS = 30_000
const CHECK_TIMEOUT_MS = 120_000

// Stops waiting for `p` after `ms`. It cannot kill the command: Bun's `$` gives no handle
// to the child process, so a command that outlives the timeout runs on until it exits.
const withTimeout = (p, ms) => {
  let timer
  const limit = new Promise((_, reject) => {
    timer = setTimeout(() => reject(new Error(`discipline hook timed out after ${ms / 1000}s`)), ms)
    timer?.unref?.()
  })
  return Promise.race([Promise.resolve(p), limit]).finally(() => clearTimeout(timer))
}

export const Discipline = async ({ $, directory }) => ({
  // A new session takes this worktree's lease; it never blocks the session.
  event: async ({ event }) => {
    try {
      if (event.type !== "session.created") return
      const start = new Response(JSON.stringify({ input: { sessionID: event.properties?.sessionID }, cwd: event.properties?.info?.directory ?? directory }))
      await withTimeout($`discipline hook run --agent opencode --event session-start < ${start}`.nothrow().quiet(), HOOK_TIMEOUT_MS)
    } catch {}
  },
  "tool.execute.before": async (input, output) => {
    try {
      if (!EDIT_TOOLS.includes(input.tool) && !SHELL_TOOLS.includes(input.tool)) return
      if (input.callID && SHELL_TOOLS.includes(input.tool)) {
        if (SHELL_ARGS.size > 256) SHELL_ARGS.clear()
        SHELL_ARGS.set(input.callID, output?.args)
      }
      const call = new Response(JSON.stringify({ input, output, cwd: directory }))
      await withTimeout($`discipline hook run --agent opencode --event pre-tool --observe < ${call}`.cwd(directory).nothrow().quiet(), HOOK_TIMEOUT_MS)
    } catch {}
  },
  "tool.execute.after": async (input, output) => {
    if (SHELL_TOOLS.includes(input.tool)) {
      try {
        const args = input.args ?? SHELL_ARGS.get(input.callID) ?? {}
        SHELL_ARGS.delete(input.callID)
        const ran = new Response(JSON.stringify({
          input: { tool: input.tool, sessionID: input.sessionID },
          output: { args, metadata: output?.metadata, output: output?.output },
          cwd: directory
        }))
        await withTimeout($`discipline hook run --agent opencode --event post-tool < ${ran}`.cwd(directory).nothrow().quiet(), HOOK_TIMEOUT_MS)
      } catch {}
      return
    }
    try {
      if (!EDIT_TOOLS.includes(input.tool)) return
      const r = await withTimeout($`discipline hook run --agent opencode --observe`.cwd(directory).nothrow().quiet(), CHECK_TIMEOUT_MS)
      if (r.exitCode !== 0) {
        output.output += "\n\n" + r.stdout.toString() + r.stderr.toString()
      }
    } catch {}
  },
})

export default {
  id: "discipline",
  setup({ tool, event, location, session }) {
    const directory = location?.directory ?? process.cwd()
    // `opencode run --standalone` serves this plugin over stdio (`serve --stdio`; the
    // background service is `serve --service`), and exits before a continuation runs.
    const STANDALONE = process.argv.includes("--stdio")
    // Each session's turn so far, by session id.
    const TURNS = new Map()
    const TURN_ERROR = () => {}

    // OpenCode 2.x has no stop hook: at the end of a turn the final message and whether
    // a tool call followed it are sent to `hook run --event stop`, which judges them
    // (`[hooks.premature-stop]`, `[hooks.verified-stop]`). It never holds the session:
    // nothing waits for it.
    const endOfTurn = async (sessionID, turn, cwd) => {
      const payload = new Response(JSON.stringify({
        hook_event_name: "Stop",
        session_id: sessionID,
        last_assistant_message: turn.text,
        tool_call_followed: turn.toolAfter,
        standalone: STANDALONE,
        cwd
      }))
      await withTimeout($`discipline hook run --agent opencode --event stop --observe < ${payload}`.cwd(cwd).nothrow().quiet(), CHECK_TIMEOUT_MS)
    }

    // OpenCode 2.x registers no event callback: `event.subscribe()` returns the events
    // as an async iterable. A session new to this plugin takes the lease on the first of
    // `session.created` (not delivered to a plugin loaded after the session was made,
    // as under `opencode run --standalone`) and `session.execution.started`.
    const events = event?.subscribe?.()
    if (typeof events?.[Symbol.asyncIterator] === "function") {
      const leased = new Set()
      ;(async () => {
        for await (const e of events) {
          try {
            const turnID = e?.data?.sessionID
            if (typeof turnID === "string") {
              if (e.type === "session.execution.started") {
                if (TURNS.size > 256) TURNS.clear()
                TURNS.set(turnID, { run: (TURNS.get(turnID)?.run ?? 0) + 1, text: undefined, message: undefined, toolAfter: false, cwd: undefined })
              } else if (TURNS.has(turnID)) {
                const turn = TURNS.get(turnID)
                // `session.execution.succeeded` names no directory (RUN, 2.0.28); the turn's other events do.
                if (typeof e.location?.directory === "string") turn.cwd = e.location.directory
                if (e.type === "session.text.ended" && typeof e.data.text === "string") {
                  const same = turn.message === e.data.assistantMessageID && !turn.toolAfter && turn.text !== undefined
                  turn.text = same ? turn.text + "\n\n" + e.data.text : e.data.text
                  turn.message = e.data.assistantMessageID
                  turn.toolAfter = false
                } else if (e.type === "session.tool.called" || (e.type === "session.step.ended" && e.data.finish === "tool-calls")) {
                  turn.toolAfter = true
                } else if (e.type === "session.execution.succeeded") {
                  endOfTurn(turnID, { ...turn }, turn.cwd ?? directory).catch(TURN_ERROR)
                }
              }
            }
          } catch {}
          try {
            if (e?.type !== "session.created" && e?.type !== "session.execution.started") continue
            const sessionID = e.data?.sessionID
            if (typeof sessionID !== "string" || leased.has(sessionID)) continue
            leased.add(sessionID)
            const start = new Response(JSON.stringify({
              input: { sessionID },
              cwd: e.location?.directory ?? directory
            }))
            await withTimeout($`discipline hook run --agent opencode --event session-start < ${start}`.nothrow().quiet(), HOOK_TIMEOUT_MS)
          } catch {}
        }
      })().catch(() => {})
    }

    tool?.hook?.("execute.before", async (call) => {
      try {
        const toolName = call.tool ?? call.input?.tool
        if (!EDIT_TOOLS.includes(toolName) && !SHELL_TOOLS.includes(toolName)) return
        const payload = new Response(JSON.stringify({
          input: { tool: toolName, sessionID: call.sessionID },
          output: { args: call.input ?? {} },
          cwd: directory
        }))
        await withTimeout($`discipline hook run --agent opencode --event pre-tool --observe < ${payload}`.cwd(directory).nothrow().quiet(), HOOK_TIMEOUT_MS)
      } catch {}
    })

    tool?.hook?.("execute.after", async (call) => {
      const shellName = call.tool ?? call.input?.tool
      if (SHELL_TOOLS.includes(shellName)) {
        try {
          const text = Array.isArray(call.result?.content)
            ? call.result.content.filter((c) => typeof c?.text === "string").map((c) => c.text).join("\n")
            : call.result?.output
          const ran = new Response(JSON.stringify({
            input: { tool: shellName, sessionID: call.sessionID },
            output: {
              args: call.input ?? {},
              metadata: call.result?.metadata,
              output: typeof text === "string" ? text : undefined
            },
            cwd: directory
          }))
          await withTimeout($`discipline hook run --agent opencode --event post-tool < ${ran}`.cwd(directory).nothrow().quiet(), HOOK_TIMEOUT_MS)
        } catch {}
        return
      }
      try {
        const toolName = call.tool ?? call.input?.tool
        if (!EDIT_TOOLS.includes(toolName)) return
        const none = new Response("")
        const r = await withTimeout($`discipline hook run --agent opencode --observe < ${none}`.cwd(directory).nothrow().quiet(), CHECK_TIMEOUT_MS)
        if (r.exitCode !== 0 && call.result) {
          const report = "\n\n" + r.stdout.toString() + r.stderr.toString()
          if (Array.isArray(call.result.content)) {
            const part = call.result.content.find((c) => c?.type === "text" && typeof c.text === "string")
            if (part) part.text += report
            else call.result.content.push({ type: "text", text: report.trimStart() })
          } else if (typeof call.result.output === "string") {
            call.result.output += report
          }
        }
      } catch {}
    })
  }
}

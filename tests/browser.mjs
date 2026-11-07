// Opens a page of the sandbox site in headless Chrome, logged in, and runs scripts in it as a person would use it:
// clicking real buttons, so the page's own JavaScript is what gets tested. No packages: it talks to Chrome over the
// DevTools protocol on a pipe (--remote-debugging-pipe).
//
//   node tests/browser.mjs <url> <curl cookie jar> <script> [<script> ...]
//
// Each script is a JavaScript expression (a promise is awaited). Prints "RESULT <n>: <its value>" for each. A script
// that makes the page navigate (a form submitted) is followed by the next page's load before the next script runs.
// Dialogs (confirm, alert) print "DIALOG: <message>", and are accepted, or dismissed if GFCFP_BROWSER_DIALOG=dismiss.
// JavaScript errors on the page print "JS ERROR: ...". Chrome comes from GFCFP_TEST_CHROME, its profile goes in
// GFCFP_BROWSER_PROFILE (a folder, emptied first).

import { spawn } from 'node:child_process';
import { readFileSync, rmSync, mkdirSync } from 'node:fs';

const [url, jar, ...scripts] = process.argv.slice(2);
const chrome = process.env.GFCFP_TEST_CHROME;
const profile = process.env.GFCFP_BROWSER_PROFILE;
const accept = process.env.GFCFP_BROWSER_DIALOG !== 'dismiss';
if (!url || !jar || !scripts.length || !chrome || !profile) {
  console.error('usage: GFCFP_TEST_CHROME=<chrome> GFCFP_BROWSER_PROFILE=<folder> node tests/browser.mjs <url> <cookie jar> <script> [<script> ...]');
  process.exit(2);
}
rmSync(profile, { recursive: true, force: true });
mkdirSync(profile, { recursive: true });

const proc = spawn(chrome, [
  '--headless=new', '--no-sandbox', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--disable-extensions',
  '--remote-debugging-pipe', `--user-data-dir=${profile}`, 'about:blank',
], { stdio: ['ignore', 'ignore', 'ignore', 'pipe', 'pipe'] });
const quit = (code) => { try { proc.kill('SIGKILL'); } catch {} process.exit(code); };
setTimeout(() => { console.log('TIMEOUT: the browser took over 120 seconds'); quit(1); }, 120000).unref();
proc.on('error', (e) => { console.log(`BROWSER ERROR: ${e.message}`); quit(1); });

// The protocol: JSON messages, each ended by a NUL byte. Chrome reads fd 3 and writes fd 4.
let nextId = 1;
const pending = new Map();
const listeners = new Set();
let buffer = Buffer.alloc(0);
proc.stdio[4].on('data', (chunk) => {
  buffer = Buffer.concat([buffer, chunk]);
  let end;
  while ((end = buffer.indexOf(0)) >= 0) {
    const message = JSON.parse(buffer.subarray(0, end).toString('utf8'));
    buffer = buffer.subarray(end + 1);
    if (message.id && pending.has(message.id)) {
      const { resolve, reject, method } = pending.get(message.id);
      pending.delete(message.id);
      message.error ? reject(new Error(`${method}: ${message.error.message}`)) : resolve(message.result);
    } else {
      listeners.forEach((listener) => listener(message));
    }
  }
});
const send = (method, params = {}, sessionId) => new Promise((resolve, reject) => {
  const id = nextId++;
  pending.set(id, { resolve, reject, method });
  proc.stdio[3].write(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }) + '\0');
});
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const until = async (test, ms, what) => {
  for (const start = Date.now(); !test(); await sleep(50)) {
    if (Date.now() - start > ms) { throw new Error(`waited ${ms / 1000} seconds for ${what}`); }
  }
};

try {
  const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
  const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
  const page = (method, params) => send(method, params, sessionId);
  let starts = 0;
  let loads = 0;
  listeners.add(({ method, params, sessionId: from }) => {
    if (from !== sessionId) { return; }
    if (method === 'Page.frameStartedLoading' && params.frameId === targetId) { starts++; }
    if (method === 'Page.loadEventFired') { loads++; }
    if (method === 'Page.javascriptDialogOpening') {
      console.log(`DIALOG: ${params.message}`);
      page('Page.handleJavaScriptDialog', { accept }).catch(() => {});
    }
    if (method === 'Runtime.exceptionThrown') {
      const d = params.exceptionDetails;
      console.log(`JS ERROR: ${(d.exception && d.exception.description) || d.text} (${d.url || 'inline'}:${d.lineNumber})`);
    }
    if (method === 'Runtime.consoleAPICalled' && params.type === 'error') {
      console.log(`JS ERROR: console: ${params.args.map((a) => a.value ?? a.description ?? '').join(' ')}`);
    }
  });
  await page('Page.enable');
  await page('Runtime.enable');
  await page('Network.enable');

  // The logged-in session, from curl's cookie jar (curl marks HttpOnly cookies with a "#HttpOnly_" prefix).
  const origin = new URL(url).origin;
  for (const line of readFileSync(jar, 'utf8').split('\n')) {
    const httpOnly = line.startsWith('#HttpOnly_');
    const fields = (httpOnly ? line.slice(10) : line).split('\t');
    if ((line.startsWith('#') && !httpOnly) || fields.length < 7) { continue; }
    await page('Network.setCookie', { url: origin + fields[2], name: fields[5], value: fields[6].trim(), path: fields[2], httpOnly });
  }

  await page('Page.navigate', { url });
  await until(() => loads > 0, 60000, 'the page to load');

  for (const [n, script] of scripts.entries()) {
    const before = { starts, loads };
    let value;
    try {
      const r = await page('Runtime.evaluate', { expression: script, awaitPromise: true, returnByValue: true, userGesture: true });
      value = r.exceptionDetails ? `ERROR ${(r.exceptionDetails.exception && r.exceptionDetails.exception.description) || r.exceptionDetails.text}` : String(r.result.value);
    } catch (e) {
      value = `ERROR ${e.message}`;
    }
    console.log(`RESULT ${n + 1}: ${value}`);
    // A form the script submitted: wait for the next page before the next script.
    await sleep(500);
    if (starts > before.starts) {
      await until(() => loads > before.loads, 60000, 'the next page to load');
    }
  }
  await send('Browser.close').catch(() => {});
  quit(0);
} catch (e) {
  console.log(`BROWSER ERROR: ${e.message}`);
  quit(1);
}

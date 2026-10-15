import assert from 'node:assert/strict'
import { createServer } from 'node:http'
import { parse } from 'node:url'
import { once } from 'node:events'
import { createRequire } from 'node:module'
import test from 'node:test'

const require = createRequire(import.meta.url)
const { proxyRequest } = require('next/dist/server/lib/router-utils/proxy-request.js')

// Exercise Next's actual external-rewrite transport against a local HTTP double;
// Laravel authorization/CSRF enforcement is covered by its feature tests.
test('frontend consent rewrite forwards form method, session, CSRF and response cookies', async (t) => {
  const received = []
  const laravel = createServer(async (req, res) => {
    let body = ''
    for await (const chunk of req) body += chunk
    received.push({ url: req.url, method: req.method, cookie: req.headers.cookie, body })
    res.writeHead(302, { location: '/profile', 'set-cookie': 'laravel_session=rotated; HttpOnly; Path=/' })
    res.end()
  })
  laravel.listen(0, '127.0.0.1'); await once(laravel, 'listening')
  t.after(() => { laravel.closeAllConnections(); laravel.close() })
  const old = process.env.LARAVEL_API_ORIGIN
  process.env.LARAVEL_API_ORIGIN = `http://127.0.0.1:${laravel.address().port}`
  const { default: config } = await import(`../next.config.mjs?consent-proxy=${Date.now()}`)
  if (old === undefined) delete process.env.LARAVEL_API_ORIGIN
  else process.env.LARAVEL_API_ORIGIN = old
  const routes = await config.rewrites()
  const route = routes.beforeFiles.find(r => r.source === '/profile/learning-consent')
  assert.ok(route, 'consent must have an explicit Laravel rewrite')
  const frontend = createServer((req, res) => {
    assert.equal(req.url, route.source)
    proxyRequest(req, res, parse(route.destination, true)).catch(err => res.destroy(err))
  })
  frontend.listen(0, '127.0.0.1'); await once(frontend, 'listening')
  t.after(() => { frontend.closeAllConnections(); frontend.close() })
  for (const consent of ['1', '0']) {
    const body = new URLSearchParams({ _method: 'PUT', _token: 'synthetic-csrf', consent,
      version: 'learning-feedback-bandit-2026-10-08' }).toString()
    const response = await fetch(`http://127.0.0.1:${frontend.address().port}${route.source}`, {
      method: 'POST', redirect: 'manual',
      headers: { cookie: 'laravel_session=synthetic-session', 'content-type': 'application/x-www-form-urlencoded' }, body,
    })
    await response.text()
    assert.equal(response.status, 302)
    assert.equal(response.headers.get('location'), '/profile')
    assert.match(response.headers.get('set-cookie'), /laravel_session=rotated/)
    assert.deepEqual(received.at(-1), { url: '/profile/learning-consent', method: 'POST',
      cookie: 'laravel_session=synthetic-session', body })
  }
})

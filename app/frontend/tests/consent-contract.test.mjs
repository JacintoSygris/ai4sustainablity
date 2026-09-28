import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync, readdirSync } from 'node:fs'

test('Next and Blade ship byte-identical shared assets', () => {
  const base = new URL('../public/consent/', import.meta.url)
  const mirror = new URL('../../web/public/consent/', import.meta.url)
  assert.deepEqual(readdirSync(base).sort(), readdirSync(mirror).sort())
  for (const name of readdirSync(base)) assert.equal(readFileSync(new URL(name, base), 'utf8'), readFileSync(new URL(name, mirror), 'utf8'), name)
})

/**
 * The one place integer centavos become human text — the mirror of the backend's
 * `App\Domain\Money\Money`.
 *
 * Money is integer centavos in every layer. There is no parsing here and no arithmetic:
 * the browser formats amounts the server computed, and never computes one itself. Every
 * centavo in this system is created or destroyed in exactly one place, `Money::fraction()`,
 * on the server. See docs/01-architecture.md.
 */

const CURRENCY_SYMBOL = '₱'

function assertWholeCentavos(cents: number): void {
  if (!Number.isInteger(cents)) {
    throw new Error(`Money is integer centavos; got ${cents}.`)
  }
}

function group(cents: number): string {
  const absolute = Math.abs(cents)
  const pesos = Math.floor(absolute / 100)
  const remainder = (absolute % 100).toString().padStart(2, '0')

  return `${pesos.toLocaleString('en-PH')}.${remainder}`
}

/** `123456` → `"₱1,234.56"`. Negative amounts sign before the symbol: `"-₱500.00"`. */
export function formatCentavos(cents: number): string {
  assertWholeCentavos(cents)

  const sign = cents < 0 ? '-' : ''

  return `${sign}${CURRENCY_SYMBOL}${group(cents)}`
}

/** `123456` → `"1,234.56"`. For table cells whose column header already names the currency. */
export function formatCentavosPlain(cents: number): string {
  assertWholeCentavos(cents)

  const sign = cents < 0 ? '-' : ''

  return `${sign}${group(cents)}`
}

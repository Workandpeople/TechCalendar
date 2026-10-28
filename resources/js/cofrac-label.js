export function cofracLabel(value) {
    return String(value ?? '').replace(/\bcoffrac\b/gi, word => word === 'COFFRAC' ? 'COFRAC' : word === 'coffrac' ? 'cofrac' : 'Cofrac');
}

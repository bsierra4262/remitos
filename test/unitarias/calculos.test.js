/**
 * Remitos y Comprobantes — Intellihelp
 *
 * Pruebas unitarias de app/js/calculos.js (importes, CUIT y montos en letras).
 *
 *   node --test test/unitarias/
 *
 * Los casos de importes y CUIT están en casos-importes.json y se usan también en
 * importes.test.php: la interfaz y el servidor tienen que calcular lo mismo.
 *
 * @author    Brian Gabriel Sierra <bsierra@intellihelp.tech>
 * @copyright 2026 Intellihelp
 */
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const c = require(path.join(__dirname, '..', '..', 'app', 'js', 'calculos.js'));
const casos = require('./casos-importes.json');

test('importes en formato argentino', () => {
  for (const [entrada, esperado] of casos.numeros) {
    const n = c.numeroAR(entrada);
    if (esperado === null) assert.ok(Number.isNaN(n), `«${entrada}» debería ser inválido`);
    else assert.equal(n, esperado, `«${entrada}»`);
  }
});

test('totales de comprobantes en centavos', () => {
  for (const caso of casos.totales) {
    const t = c.compTotals(caso.doc);
    assert.deepEqual(t.centavos, caso.centavos, caso.nombre);
    assert.deepEqual(t.errores, [], caso.nombre);
  }
});

test('un importe inválido se informa como error y no suma', () => {
  const t = c.compTotals({ letra: 'A', alicuota: '21', items: [{ cantidad: '1', precio: 'abc' }] });
  assert.equal(t.errores.length, 1);
  assert.equal(t.total, 0);
  assert.equal(c.compTotals({ letra: 'A', alicuota: '19', items: [] }).errores.length, 1);
});

test('subtotal de un renglón redondeado a centavos', () => {
  assert.equal(c.subtotalItem({ cantidad: '2', precio: '1.234,56' }), 2469.12);
  assert.equal(c.subtotalItem({ cantidad: 'x', precio: '1' }), 0);
});

test('montos en letras', () => {
  const casosLetras = [
    ['1', 'Un peso'],
    ['1.995', 'Mil novecientos noventa y cinco pesos'],
    ['1,995', 'Dos pesos'],
    ['21', 'Veintiún pesos'],
    ['22,50', 'Veintidós pesos con 50/100'],
    ['21.000', 'Veintiún mil pesos'],
    ['101.000', 'Ciento un mil pesos'],
    ['1.000.000', 'Un millón de pesos'],
    ['2.500.000', 'Dos millones quinientos mil pesos'],
    ['158.750,50', 'Ciento cincuenta y ocho mil setecientos cincuenta pesos con 50/100'],
  ];
  for (const [entrada, esperado] of casosLetras) assert.equal(c.montoALetras(entrada), esperado, entrada);
});

test('CUIT con dígito verificador', () => {
  for (const [cuit, valido] of casos.cuit) assert.equal(c.cuitValido(cuit), valido, cuit);
  assert.equal(c.problemaCuit('12345678'), '', 'un DNI es válido');
  assert.notEqual(c.problemaCuit('30-12345678-0'), '');
  assert.equal(c.cuitConGuiones('20123456786'), '20-12345678-6');
  assert.equal(c.formatoCuitODni('12345678'), '12345678');
});

test('contenido obligatorio del remito (RG 1415, Anexo V)', () => {
  const completo = {
    fecha: '05/11/2026',
    cliente: { razonSocial: 'X', condIVA: 'Responsable Inscripto', cuit: '20-12345678-6', domicilio: 'Calle 1' },
    items: [{ descripcion: 'Bien', cantidad: '1' }],
    transporte: { tipo: 'propio' },
  };
  assert.deepEqual(c.faltantesRemito(completo), []);
  const cf = structuredClone(completo);
  Object.assign(cf.cliente, { condIVA: 'Consumidor Final', cuit: '', domicilio: '' });
  assert.deepEqual(c.faltantesRemito(cf), []);
  const tercero = structuredClone(completo);
  tercero.transporte = { tipo: 'tercero' };
  assert.equal(c.faltantesRemito(tercero).length, 3);
  assert.equal(c.LEYENDAS_IVA['Consumidor Final'], 'A CONSUMIDOR FINAL');
});

/**
 * IAM Power — lidhja e shitjeve të Skuadrës me Google Sheet.
 *
 * Vendoset te Google Sheet "Regjistrimi (Responses)": Extensions → Apps Script.
 * Deploy → New deployment → Web app → Execute as: Me · Who has access: Anyone.
 * URL-ja e Web App-it dhe ÇELËSI më poshtë vendosen te WordPress:
 * IAM Anëtarët → Skuadra & Bonuset → Cilësimet.
 */

var CELESI = 'VENDOS_KETU_CELESIN_NGA_WORDPRESS';
var FLETA = 'Shitjet e Skuadrës 2026+';
var PRESIN = 'Presin konfirmim';
var KOLONAT = ['ID', 'Data', 'Trajneri', 'Klienti', 'Email', 'Telefoni', 'Kursi / oferta', 'Bonus falas shtesë',
  'Shuma', 'Valuta', 'Mënyra e pagesës', 'Referenca', 'Data e pagesës', 'Pagesën e mori trajneri',
  'Bonusi %', 'Bonusi €', 'Statusi', 'Shënime', 'Përditësuar'];

function doPost(e) {
  var d;
  try {
    d = JSON.parse(e.postData.contents);
  } catch (err) {
    return ContentService.createTextOutput('BAD');
  }
  if (!d || d.secret !== CELESI) {
    return ContentService.createTextOutput('FORBIDDEN');
  }
  var lock = LockService.getScriptLock();
  lock.waitLock(20000);
  try {
    var sh = fleta_(FLETA, KOLONAT, '#0B1A33');
    var row = [d.id, d.data, d.trajneri, d.klienti, d.email, d.telefoni, d.kursi, d.bonuset,
      d.shuma, d.valuta, d.pagesa, d.referenca, d.data_pageses, d.mori_trajneri,
      d.bonus_pct / 100, d.bonus, d.statusi, d.shenime, new Date()];
    var ids = sh.getRange(2, 1, Math.max(sh.getLastRow() - 1, 1), 1).getValues();
    var at = -1;
    for (var i = 0; i < ids.length; i++) {
      if (String(ids[i][0]) === String(d.id)) { at = i + 2; break; }
    }
    if (at === -1) {
      sh.appendRow(row);
      at = sh.getLastRow();
    } else {
      sh.getRange(at, 1, 1, row.length).setValues([row]);
    }
    sh.getRange(at, 9).setNumberFormat('#,##0.00');
    sh.getRange(at, 15).setNumberFormat('0%');
    sh.getRange(at, 16).setNumberFormat('€#,##0.00');
    var color = d.statusi.indexOf('Pret') === 0 ? '#FFE9C7' : (d.statusi.indexOf('Refuz') === 0 ? '#FDE3E3' : '#DDF3E4');
    sh.getRange(at, 17).setBackground(color).setFontWeight('bold');
    rifresko_presin_(sh);
  } finally {
    lock.releaseLock();
  }
  return ContentService.createTextOutput('OK');
}

/** Fleta e vogël "Presin konfirmim": lexohet çdo ditë nga Claude. */
function rifresko_presin_(sh) {
  var p = fleta_(PRESIN, ['ID', 'Data', 'Trajneri', 'Klienti', 'Email', 'Kursi / oferta', 'Bonus falas shtesë', 'Shuma', 'Mënyra e pagesës', 'Referenca'], '#C9A14A');
  if (p.getLastRow() > 1) {
    p.getRange(2, 1, p.getLastRow() - 1, p.getLastColumn()).clearContent();
  }
  var data = sh.getRange(2, 1, Math.max(sh.getLastRow() - 1, 1), KOLONAT.length).getValues();
  var out = [];
  data.forEach(function (r) {
    if (String(r[16]).indexOf('Pret') === 0) {
      out.push([r[0], r[1], r[2], r[3], r[4], r[6], r[7], r[8] + ' ' + r[9], r[10], r[11]]);
    }
  });
  if (out.length) {
    p.getRange(2, 1, out.length, out[0].length).setValues(out);
  }
}

function fleta_(emri, koka, ngjyra) {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var sh = ss.getSheetByName(emri);
  if (!sh) {
    sh = ss.insertSheet(emri, 0);
    sh.getRange(1, 1, 1, koka.length).setValues([koka])
      .setBackground(ngjyra).setFontColor('#FFFFFF').setFontWeight('bold').setWrap(true);
    sh.setFrozenRows(1);
    sh.setColumnWidths(1, koka.length, 140);
  }
  return sh;
}

/** Provë: Run → testo_ (shton një rresht prove te fleta). */
function testo_() {
  doPost({ postData: { contents: JSON.stringify({ secret: CELESI, id: 'TEST', data: 'sot', trajneri: 'Test', klienti: 'Klient prove', email: 'test@example.com', telefoni: '', kursi: 'Paketa 1', bonuset: '', shuma: 1, valuta: 'EUR', pagesa: 'Kesh', referenca: '', data_pageses: '', mori_trajneri: 'Jo', bonus_pct: 10, bonus: 0.08, statusi: 'Pret konfirmim', shenime: 'fshije këtë rresht' }) } });
}

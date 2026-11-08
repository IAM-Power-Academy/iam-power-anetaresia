/**
 * IAM Power — "Shitjet e Skuadrës 2026"
 *
 * Shitjet e Skuadrës ruhen VETËM në këtë fletë (jo në WordPress).
 *  - Trajneret i regjistrojnë te kursi i Skuadrës në iampower.al → rreshti shtohet këtu vetë.
 *  - Mimoza i konfirmon këtu: kolona "Statusi" → "Konfirmo" (klienti aktivizohet vetë në iampower.al)
 *    ose "Refuzo".
 *  - Webfaqja lexon prej këtu vetëm totalet (bonuset e trajnereve dhe grafikonet e Mimozës).
 *
 * Vendosja (një herë):
 *  1. Extensions → Apps Script → ngjit këtë kod.
 *  2. Te CELESI vendos çelësin nga WordPress: IAM Anëtarët → Skuadra & Bonuset → Cilësimet.
 *  3. Zgjidh funksionin "instalo" → Run → Allow.
 *  4. Deploy → New deployment → Web app → Execute as: Me · Who has access: Anyone → Deploy.
 *  5. URL-ja e Web App-it vendoset te WordPress, te "Apps Script (Web App URL)".
 */

var CELESI = 'VENDOS_KETU_CELESIN_NGA_WORDPRESS';
var WP_URL = 'https://iampower.al/wp-json/iampower/v1/fleta';
var FLETA = 'Shitjet';
var PRESIN = 'Presin konfirmim';
var DOSJA_DESHMIVE = 'Dëshmitë e pagesave';

var KOLONAT = ['ID', 'Data', 'Trajneri', 'Klienti', 'Email', 'Telefoni', 'Kursi / oferta', 'Bonus falas shtesë',
  'Shuma', 'Valuta', 'Mënyra e pagesës', 'Referenca', 'Data e pagesës', 'Pagesën e mori trajneri',
  'Bonusi %', 'Bonusi €', 'Statusi', 'Shënime të trajneres', 'Dëshmia', 'Shënimi im', 'Aktivizuar / vendosur më',
  'Trajneri ID', 'Paketa ID', 'Kurset ID'];
var K = {}; // emri → numri i kolonës (1-based)
KOLONAT.forEach(function (n, i) { K[n] = i + 1; });

var ST_PRET = 'Pret konfirmim';
var ST_KONFIRMO = 'Konfirmo';
var ST_REFUZO = 'Refuzo';
var ST_OK = 'Konfirmuar dhe aktivizuar';
var ST_NO = 'Refuzuar';
var STATUSET = [ST_PRET, ST_KONFIRMO, ST_REFUZO, ST_OK, ST_NO];

/* ---------------- Vendosja ---------------- */

function instalo() {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  ss.setSpreadsheetTimeZone('Europe/Belgrade');
  var sh = fleta_();
  ScriptApp.getProjectTriggers().forEach(function (t) {
    if (t.getHandlerFunction() === 'kurNdryshohet') { ScriptApp.deleteTrigger(t); }
  });
  ScriptApp.newTrigger('kurNdryshohet').forSpreadsheet(ss).onEdit().create();
  dosja_();
  rifresko_presin_(sh);
  SpreadsheetApp.getUi().alert('Fleta "Shitjet e Skuadrës 2026" u lidh. Tani bëj Deploy → Web app.');
}

function fleta_() {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var sh = ss.getSheetByName(FLETA);
  if (!sh) {
    var first = ss.getSheets()[0];
    if (first.getLastRow() <= 1) { sh = first.setName(FLETA); } else { sh = ss.insertSheet(FLETA, 0); }
  }
  var head = sh.getRange(1, 1, 1, KOLONAT.length);
  head.setValues([KOLONAT]).setFontWeight('bold').setFontColor('#ffffff').setBackground('#0B1A33').setWrap(true);
  sh.setFrozenRows(1);
  sh.setFrozenColumns(4);
  sh.getRange('B:B').setNumberFormat('@');
  sh.getRange('M:M').setNumberFormat('@');
  sh.getRange('I:I').setNumberFormat('#,##0.00');
  sh.getRange('O:O').setNumberFormat('0%');
  sh.getRange('P:P').setNumberFormat('€#,##0.00');
  var rule = SpreadsheetApp.newDataValidation().requireValueInList(STATUSET, true).setAllowInvalid(false).build();
  sh.getRange(2, K['Statusi'], 1999, 1).setDataValidation(rule);
  sh.hideColumns(K['Trajneri ID'], 3);
  sh.setColumnWidth(K['Statusi'], 190);
  sh.setColumnWidth(K['Klienti'], 170);
  return sh;
}

function dosja_() {
  var file = DriveApp.getFileById(SpreadsheetApp.getActiveSpreadsheet().getId());
  var parents = file.getParents();
  var parent = parents.hasNext() ? parents.next() : DriveApp.getRootFolder();
  var it = parent.getFoldersByName(DOSJA_DESHMIVE);
  return it.hasNext() ? it.next() : parent.createFolder(DOSJA_DESHMIVE);
}

/* ---------------- Kërkesat nga webfaqja ---------------- */

function doGet() {
  return ContentService.createTextOutput('IAM Power');
}

function doPost(e) {
  var d;
  try {
    d = JSON.parse(e.postData.contents);
  } catch (err) {
    return json_({ ok: false, error: 'BAD' });
  }
  if (!d || CELESI.indexOf('VENDOS_') === 0 || d.secret !== CELESI) {
    return json_({ ok: false, error: 'FORBIDDEN' });
  }
  if (d.action === 'summary') {
    return json_(permbledhja_());
  }
  if (d.action === 'list') {
    return json_(lista_(d.trajneri_id));
  }
  var lock = LockService.getScriptLock();
  lock.waitLock(25000);
  try {
    var sh = fleta_();
    if (d.action === 'new') {
      if (gjej_(sh, d.id) > 0) {
        return json_({ ok: true, duplicate: true }); // E arritur më parë: mos e prek statusin.
      }
      if (d.deshmia_b64) {
        try {
          var blob = Utilities.newBlob(Utilities.base64Decode(d.deshmia_b64), d.deshmia_tipi || 'application/octet-stream',
            '#' + d.id + ' ' + (d.klienti || '') + ' - ' + (d.deshmia_emri || 'deshmia'));
          d.deshmia = dosja_().createFile(blob).getUrl();
        } catch (err2) {
          d.deshmia = 'Dëshmia nuk u ruajt: ' + err2;
        }
      }
      shkruaj_(sh, d);
    } else if (d.action === 'import') {
      (d.rows || []).forEach(function (r) { shkruaj_(sh, r); });
    } else {
      return json_({ ok: false, error: 'Veprim i panjohur.' });
    }
    rifresko_presin_(sh);
  } finally {
    lock.releaseLock();
  }
  return json_({ ok: true });
}

function json_(o) {
  return ContentService.createTextOutput(JSON.stringify(o)).setMimeType(ContentService.MimeType.JSON);
}

/** Shton rreshtin (ose e përditëson nëse ID ekziston). */
function shkruaj_(sh, d) {
  var at = gjej_(sh, d.id);
  var existing = at > 0;
  if (!existing) { at = Math.max(sh.getLastRow() + 1, 2); }
  var tvsh = d.tvsh ? Number(d.tvsh) : 18;
  var row = [d.id, d.data || '', d.trajneri || '', d.klienti || '', d.email || '', d.telefoni || '', d.kursi || '',
    d.bonuset || '', Number(d.shuma) || 0, d.valuta || 'EUR', d.pagesa || '', d.referenca || '', d.data_pageses || '',
    d.mori_trajneri || 'Jo', (Number(d.bonus_pct) || 0) / 100,
    '=ROUND(I' + at + '/' + (1 + tvsh / 100) + '*O' + at + ',2)',
    d.statusi || ST_PRET, d.shenime || '', d.deshmia || '', '', d.aktivizuar || '',
    d.trajneri_id || '', d.paketa_id || '', d.kurset_id || ''];
  if (existing) {
    // Mos e prek "Shënimi im" që e shkruan Mimoza.
    row[K['Shënimi im'] - 1] = sh.getRange(at, K['Shënimi im']).getValue();
  }
  sh.getRange(at, 1, 1, row.length).setValues([row]);
  if (d.deshmia && String(d.deshmia).indexOf('http') === 0) {
    sh.getRange(at, K['Dëshmia']).setFormula('=HYPERLINK("' + d.deshmia + '","📎 Hap")');
  }
  ngjyros_(sh, at);
}

function gjej_(sh, id) {
  var n = sh.getLastRow() - 1;
  if (n < 1) { return -1; }
  var ids = sh.getRange(2, 1, n, 1).getValues();
  for (var i = 0; i < ids.length; i++) {
    if (String(ids[i][0]) === String(id)) { return i + 2; }
  }
  return -1;
}

function ngjyros_(sh, r) {
  var s = String(sh.getRange(r, K['Statusi']).getValue());
  var color = s === ST_OK ? '#DDF3E4' : (s === ST_NO ? '#FDE3E3' : '#FFE9C7');
  sh.getRange(r, K['Statusi']).setBackground(color).setFontWeight('bold');
}

/* ---------------- Konfirmimi nga Mimoza ---------------- */

/** Ndizet kur ndryshon diçka te fleta (trigger i instaluar nga "instalo"). */
function kurNdryshohet(e) {
  var sh = e.range.getSheet();
  if (sh.getName() !== FLETA) { return; }
  var r0 = e.range.getRow();
  var c0 = e.range.getColumn();
  var c1 = c0 + e.range.getNumColumns() - 1;
  if (r0 + e.range.getNumRows() - 1 < 2) { return; }
  var ndryshoi = false;
  if (c0 <= K['Statusi'] && c1 >= K['Statusi']) {
    for (var r = Math.max(r0, 2); r < r0 + e.range.getNumRows(); r++) {
      var v = String(sh.getRange(r, K['Statusi']).getValue());
      if (v === ST_KONFIRMO) {
        konfirmo_(sh, r);
      } else if (v === ST_REFUZO) {
        sh.getRange(r, K['Statusi']).setValue(ST_NO);
        sh.getRange(r, K['Aktivizuar / vendosur më']).setValue(Utilities.formatDate(new Date(), 'Europe/Belgrade', 'dd.MM.yyyy HH:mm'));
      }
      ngjyros_(sh, r);
    }
    rifresko_presin_(sh);
    ndryshoi = true;
  }
  if (ndryshoi || (c0 <= K['Bonusi %'] && c1 >= K['Shuma'])) {
    thirr_({ action: 'refresh' });
  }
}

function konfirmo_(sh, r) {
  var v = sh.getRange(r, 1, 1, KOLONAT.length).getValues()[0];
  var res = thirr_({
    action: 'approve',
    id: v[K['ID'] - 1],
    klienti: v[K['Klienti'] - 1],
    email: v[K['Email'] - 1],
    paketa_id: v[K['Paketa ID'] - 1],
    kurset_id: String(v[K['Kurset ID'] - 1]),
    trajneri: v[K['Trajneri'] - 1]
  });
  if (res.ok) {
    sh.getRange(r, K['Statusi']).setValue(ST_OK);
    sh.getRange(r, K['Aktivizuar / vendosur më']).setValue(Utilities.formatDate(new Date(), 'Europe/Belgrade', 'dd.MM.yyyy HH:mm'));
    if (String(v[K['Shënimi im'] - 1]).indexOf('Gabim') === 0) { sh.getRange(r, K['Shënimi im']).setValue(''); }
  } else {
    sh.getRange(r, K['Statusi']).setValue(ST_PRET);
    sh.getRange(r, K['Shënimi im']).setValue('Gabim gjatë aktivizimit: ' + (res.error || 'pa përgjigje') + ' — provo sërish "Konfirmo".');
  }
}

function thirr_(o) {
  o.secret = CELESI;
  try {
    var resp = UrlFetchApp.fetch(WP_URL, {
      method: 'post', contentType: 'application/json', payload: JSON.stringify(o), muteHttpExceptions: true
    });
    return JSON.parse(resp.getContentText());
  } catch (err) {
    return { ok: false, error: String(err) };
  }
}

/* ---------------- Totalet për webfaqen ---------------- */

function muaji_(s) {
  if (s instanceof Date) { return Utilities.formatDate(s, 'Europe/Belgrade', 'yyyy-MM'); }
  var m = String(s).match(/(\d{1,2})\.(\d{1,2})\.(\d{4})/);
  return m ? m[3] + '-' + ('0' + m[2]).slice(-2) : '';
}

function dita_(s) {
  if (s instanceof Date) { return Utilities.formatDate(s, 'Europe/Belgrade', 'yyyy-MM-dd'); }
  var m = String(s).match(/(\d{1,2})\.(\d{1,2})\.(\d{4})/);
  return m ? m[3] + '-' + ('0' + m[2]).slice(-2) + '-' + ('0' + m[1]).slice(-2) : '';
}

function permbledhja_() {
  var sh = fleta_();
  var n = sh.getLastRow() - 1;
  var rows = n > 0 ? sh.getRange(2, 1, n, KOLONAT.length).getValues() : [];
  var tani = Utilities.formatDate(new Date(), 'Europe/Belgrade', 'yyyy-MM');
  var trainers = {};
  var months = {};
  var pending = 0;
  var days = {};
  // 12 muajt e fundit, edhe ata pa shitje.
  var d0 = new Date();
  for (var i = 11; i >= 0; i--) {
    var dd = new Date(d0.getFullYear(), d0.getMonth() - i, 1);
    months[Utilities.formatDate(dd, 'Europe/Belgrade', 'yyyy-MM')] = { n: 0, sold: 0, bonus: 0 };
  }
  rows.forEach(function (r) {
    if (r[0] === '' || r[0] === null) { return; }
    var tid = String(r[K['Trajneri ID'] - 1]);
    var st = String(r[K['Statusi'] - 1]);
    var shuma = Number(r[K['Shuma'] - 1]) || 0;
    var bonus = Number(r[K['Bonusi €'] - 1]) || 0;
    var mori = String(r[K['Pagesën e mori trajneri'] - 1]) === 'Po';
    var muaji = muaji_(r[K['Data'] - 1]);
    var t = trainers[tid] || (trainers[tid] = { count: 0, sold: 0, earned: 0, kept: 0, pending_n: 0, pending_c: 0, month_n: 0, month_sold: 0, month_c: 0 });
    var ok = st === ST_OK;
    var pret = st === ST_PRET || st === ST_KONFIRMO;
    if (ok) {
      t.count++; t.sold += shuma; t.earned += bonus;
      if (mori) { t.kept += bonus; }
      if (months[muaji]) { months[muaji].n++; months[muaji].sold += shuma; months[muaji].bonus += bonus; }
    }
    if (pret) { t.pending_n++; t.pending_c += bonus; pending++; }
    if ((ok || pret) && muaji === tani) { t.month_n++; t.month_sold += shuma; t.month_c += bonus; }
    var dita = dita_(r[K['Data'] - 1]);
    if ((ok || pret) && dita) {
      var x = days[dita] || (days[dita] = { t: 0, p: 0, n: 0 });
      x.t += shuma; x.n++;
      if (ok) { x.p += shuma; }
    }
  });
  var mlist = Object.keys(months).sort().map(function (k) {
    return { m: k, n: months[k].n, sold: Math.round(months[k].sold * 100) / 100, bonus: Math.round(months[k].bonus * 100) / 100 };
  });
  return { ok: true, trainers: trainers, months: mlist, pending: pending, days: days };
}

/** Shitjet e një trajneri, për "Shitjet e mia" te paneli i tij (më të rejat sipër, deri në 200). */
function lista_(tid) {
  var sh = fleta_();
  var n = sh.getLastRow() - 1;
  var rows = n > 0 ? sh.getRange(2, 1, n, KOLONAT.length).getValues() : [];
  var out = [];
  rows.forEach(function (r) {
    if (r[0] === '' || r[0] === null || String(r[K['Trajneri ID'] - 1]) !== String(tid)) { return; }
    var data = r[K['Data'] - 1];
    out.push({
      id: r[K['ID'] - 1],
      data: data instanceof Date ? Utilities.formatDate(data, 'Europe/Belgrade', 'dd.MM.yyyy HH:mm') : String(data),
      klienti: String(r[K['Klienti'] - 1]),
      email: String(r[K['Email'] - 1]),
      kursi: String(r[K['Kursi / oferta'] - 1]),
      bonuset: String(r[K['Bonus falas shtesë'] - 1]),
      shuma: Number(r[K['Shuma'] - 1]) || 0,
      valuta: String(r[K['Valuta'] - 1] || 'EUR'),
      statusi: String(r[K['Statusi'] - 1])
    });
  });
  out.reverse();
  return { ok: true, rows: out.slice(0, 200) };
}

/** Fleta e vogël "Presin konfirmim" (pa të dhëna teknike). */
function rifresko_presin_(sh) {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var p = ss.getSheetByName(PRESIN) || ss.insertSheet(PRESIN);
  var koka = ['ID', 'Data', 'Trajneri', 'Klienti', 'Email', 'Kursi / oferta', 'Shuma', 'Mënyra e pagesës', 'Referenca'];
  p.clearContents();
  p.getRange(1, 1, 1, koka.length).setValues([koka]).setFontWeight('bold').setFontColor('#0B1A33').setBackground('#C9A14A');
  p.setFrozenRows(1);
  var n = sh.getLastRow() - 1;
  if (n < 1) { return; }
  var out = [];
  sh.getRange(2, 1, n, KOLONAT.length).getValues().forEach(function (r) {
    var st = String(r[K['Statusi'] - 1]);
    if (r[0] !== '' && (st === ST_PRET || st === ST_KONFIRMO)) {
      out.push([r[0], r[1], r[2], r[3], r[4], r[6], r[8] + ' ' + r[9], r[10], r[11]]);
    }
  });
  if (out.length) { p.getRange(2, 1, out.length, koka.length).setValues(out); }
}

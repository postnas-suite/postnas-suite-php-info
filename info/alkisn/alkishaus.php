<?php
/*	alkishaus.php

	ALKIS-Auskunft
	Author: Frank Jäger, Kommunales Rechenzentrum Minden-Ravensberg/Lippe (Lemgo)

	Daten zu EINEM ALKIS-Gebäude-Objekt

	Version:
	2016-02-24 Version für norGIS-ALKIS-Import
	....
	2020-12-01 Darstellung der Datenerhebung, Klammern um Schlüsselwerte
	2020-12-02 Verschnitt Gebäude / Flurstücke
	2020-12-03 function selbstverlinkung() statt $_SERVER['PHP_SELF']
	2020-12-15 Input-Validation und Strict Comparisation (===)
	2021-12-09 Neue Parameter: $katAmtMix (Kataster-Amt-Mix), $PrntBtn (Drucken-Schaltfläche). Gemeinde in Adresse
	2022-01-13 Neue Functions LnkStf(), DsKy()
	2022-07-05 PHP 8.1: Connection verwenden bei "pg_prepare" und "pg_execute", keine NULL-Werte in String-Functions verwenden
ToDo:
	- per Relation dazugehörige Bauwerke (z.B. Überdachung) suchen, ax_sonstigesbauwerkodersonstigeeinrichtung.gehoertzu
	- Template im WMS auf Ebene Gebäude hierhin verknüpfen.
*/
ini_set("session.cookie_httponly", 1);
session_start();
$allfld = "n"; $showkey="n"; $nodebug=""; // Var. aus Parameter initalisieren
$cntget = extract($_GET); // Parameter in Variable umwandeln

// strikte Validierung aller Parameter
if (isset($gmlid)) {
	if (!preg_match('#^[0-9A-Za-z]{16}$#', $gmlid)) {die("Eingabefehler gmlid");}
} else {
	die("Fehlender Parameter");
}
if (isset($gkz)) {
	if (!preg_match('#^[0-9]{3}$#', $gkz)) {die("Eingabefehler gkz");}
} else {
	die("Fehlender Parameter");
}
if (!preg_match('#^[j|n]{0,1}$#', $showkey)) {die ("Eingabefehler showkey");}
if ($showkey === "j") {$showkey=true;} else {$showkey=false;}
if (!preg_match('#^[j|n]{0,1}$#', $allfld)) {die ("Eingabefehler allfld");}
if ($allfld === "j") {$allefelder=true;} else {$allefelder=false;}
if (!preg_match('#^j{0,1}$#', $nodebug)) {die("Eingabefehler nodebug");}

include "alkis_conf_location.php";
include "alkisfkt.php";

echo <<<END
<!doctype html>
<html lang="de">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>ALKIS Daten zum Haus</title>
	<link rel="stylesheet" type="text/css" href="alkisauszug.css">
	<link rel="shortcut icon" type="image/x-icon" href="ico/Haus.ico">
	<style type='text/css' media='print'> td.mittelspalte {width: 190px;} </style>
</head>
<body>
END;

$erlaubnis = darf_ich(); if ($erlaubnis === 0) { die('<p class="stop1">Abbruch</p></body>'); }
$dbg=$debug;
if ($nodebug === "j") {$dbg=0;} 

$con = pg_connect($dbconn." options='--application_name=ALKIS-Auskunft_alkishaus.php'");
if (!$con) echo "\n<p class='err'>Fehler beim Verbinden der DB</p>";

// G e b ä u d e
// ... g.qualitaetsangaben, 
$sqlg ="SELECT g.gml_id, g.name, g.bauweise, g.gebaeudefunktion, g.anzahlderoberirdischengeschosse AS aog, g.anzahlderunterirdischengeschosse AS aug, 
g.lagezurerdoberflaeche, g.dachgeschossausbau, g.zustand, array_to_string(g.weiteregebaeudefunktion, ',') AS wgf, g.dachform, g.hochhaus, g.hoehe, 
g.geschossflaeche, g.grundflaeche, g.umbauterraum, g.baujahr, g.dachart, 
h.beschreibung AS bbauw, h.dokumentation AS dbauw, u.beschreibung AS bfunk, u.dokumentation AS dfunk, z.beschreibung AS zustandv, z.dokumentation AS zustandd, d.beschreibung AS bdach, 
a.beschreibung AS dgaus, o.beschreibung AS oflv, o.dokumentation AS ofld,
round(st_area(g.wkb_geometry)::numeric,2) AS gebflae
FROM ax_gebaeude g 
LEFT JOIN ax_bauweise_gebaeude h ON g.bauweise = h.wert
LEFT JOIN ax_gebaeudefunktion u ON g.gebaeudefunktion = u.wert
LEFT JOIN ax_zustand_gebaeude z ON g.zustand = z.wert
LEFT JOIN ax_dachform d ON g.dachform = d.wert
LEFT JOIN ax_dachgeschossausbau_gebaeude a ON g.dachgeschossausbau = a.wert
LEFT JOIN ax_lagezurerdoberflaeche_gebaeude o ON g.lagezurerdoberflaeche = o.wert
WHERE g.gml_id= $1 AND g.endet IS NULL;";

$v = array($gmlid);
$resg = pg_prepare($con, "", $sqlg);
$resg = pg_execute($con, "", $v);
if (!$resg) {
	echo "\n<p class='err'>Fehler bei Geb&auml;ude.<br>".pg_last_error()."</p>";
	if ($dbg > 2) {echo "\n<p class='dbg'>SQL=<br>".$sqlg."<br>$1 = gml_id = '".$gmlid."'</p>";}
}
if ($dbg > 0) {
	$zeianz=pg_num_rows($resg);
	if ($zeianz > 1){
		echo "\n<p class='err'>Die Abfrage liefert mehr als ein (".$zeianz.") Geb&auml;ude-Objekt!</p>";
		if ($dbg > 2) {echo "\n<p class='dbg'>SQL=<br>".htmlentities(str_replace("$1","'".$gmlid."'",$sqlg), ENT_QUOTES, "UTF-8")."</p>";}
	}
}

// Balken
echo "\n<p class='balken geb'>ALKIS Haus ".$gmlid."&nbsp;</p>"
."\n<h2><img src='ico/Haus.png' width='16' height='16' alt=''> Haus (Geb&auml;ude)</h2>\n<hr>";

echo "<p class='nwlink noprint'>" // Umschalter: auch leere Felder
."Umschalten: <a class='nwlink' href='".selbstverlinkung()."?gkz=".$gkz."&amp;gmlid=".$gmlid.LnkStf();
if ($allefelder) {
	echo "&amp;allfld=n'>nur Felder mit Inhalt";
} else {
	echo "&amp;allfld=j'>auch leere Felder";
}
echo "</a></p>";

if (!($rowg = pg_fetch_assoc($resg))) {
	echo "\n<p class='err'><br>Kein Geb&auml;ude gefunden</p>";
	die ("Abbruch");
}

echo "\n<table class='geb'>"
."\n<tr>\n"
	."\n\t<td class='head' title=''>Attribut</td>"
	."\n\t<td class='head mittelspalte' title=''>Wert</td>"
	."\n\t<td class='head' title=''>"
		."\n\t\t<p class='erklk'>Erkl&auml;rung Kategorie</p>"
		."\n\t\t<p class='erkli'>Erkl&auml;rung Inhalt</p>"
	."\n\t</td>"
."\n</tr>";

$aog=$rowg["aog"];
$aug=$rowg["aug"];
$hoh=$rowg["hochhaus"];
if (is_null($rowg["name"])) {
	$nam="";
} else {
	$nam=trim(trim($rowg["name"], "{}"), '"'); // Gebäude-Name ist ein Array.
}

// Mehrfachbelegung theoretisch. Entklammern reicht. Mal mit und mal ohne "" drum.
$kfunk=$rowg["gebaeudefunktion"];
$bfunk=$rowg["bfunk"];
$dfunk=$rowg["dfunk"];

$baw=$rowg["bauweise"];
$bbauw=$rowg["bbauw"];
$dbauw=$rowg["dbauw"];

$ofl=$rowg["lagezurerdoberflaeche"];
$oflv=$rowg["oflv"];
$ofld=$rowg["ofld"];

$dga=$rowg["dachgeschossausbau"]; // Key
$dgav=$rowg["dgaus"];		// Value

$zus=$rowg["zustand"];		// Key
$zusv=$rowg["zustandv"];	// Value
$zusd=$rowg["zustandd"];	// Description

$wgf=$rowg["wgf"];			// Array-> kommagetr. Liste

$daf=$rowg["dachform"];		// Key
$dach=$rowg["bdach"];		// Value

$hho=trim($rowg["hoehe"], '{}');	// Höhe des Gebäudes; Array, Typ double precision[]
$gfl=$rowg["geschossflaeche"];
$grf=$rowg["grundflaeche"];
$ura=$rowg["umbauterraum"];
$bja=$rowg["baujahr"];
$daa=$rowg["dachart"];

if (($nam != "") OR $allefelder) {
	echo "\n<tr>"
		."\n\t<td class='li'>Name</td>"
		."\n\t<td>".$nam."</td>"
		."\n\t<td>"
			."\n\t\t<p class='erklk'>'Name' ist der Eigenname oder die Bezeichnung des Geb&auml;udes."
		."\n\t</td>"
	."\n</tr>";
}

// 0 bis N   L a g e bezeichnungen mit Haus- oder Pseudo-Nummer

// HAUPTgebäude
$sqll ="SELECT 'm' AS ltyp, lh.gml_id AS gmllag, sh.lage, sh.bezeichnung, lh.hausnummer, '' AS laufendenummer, ph.bezeichnung AS gemeinde
FROM ax_gebaeude gh
JOIN ax_lagebezeichnungmithausnummer lh ON lh.gml_id=ANY(gh.zeigtauf)
JOIN ax_lagebezeichnungkatalogeintrag sh ON lh.kreis=sh.kreis AND lh.gemeinde=sh.gemeinde AND lh.lage=sh.lage 
LEFT JOIN ax_gemeinde ph ON lh.land=ph.land AND lh.regierungsbezirk=ph.regierungsbezirk AND lh.kreis=ph.kreis AND lh.gemeinde=ph.gemeinde ".UnqKatAmt("lh","ph")
."WHERE gh.gml_id= $1 AND gh.endet IS NULL AND lh.endet IS NULL AND sh.endet IS NULL";

// oder NEBENgebäude
$sqll.=" UNION 
SELECT 'p' AS ltyp, ln.gml_id AS gmllag, sn.lage, sn.bezeichnung, ln.pseudonummer AS hausnummer, ln.laufendenummer, pn.bezeichnung AS gemeinde
FROM ax_gebaeude gn
JOIN ax_lagebezeichnungmitpseudonummer ln ON ln.gml_id=gn.hat
JOIN ax_lagebezeichnungkatalogeintrag sn ON ln.kreis=sn.kreis AND ln.gemeinde=sn.gemeinde AND ln.lage=sn.lage 
LEFT JOIN ax_gemeinde pn ON ln.land=pn.land AND ln.regierungsbezirk=pn.regierungsbezirk AND ln.kreis=pn.kreis AND ln.gemeinde=pn.gemeinde ".UnqKatAmt("ln","pn")
."WHERE gn.gml_id= $1 AND gn.endet IS NULL AND ln.endet IS NULL AND sn.endet IS NULL ";

$sqll.="ORDER BY bezeichnung, hausnummer ;";

$v = array($gmlid);
$resl = pg_prepare($con, "", $sqll);
$resl = pg_execute($con, "", $v);
if (!$resl) {
	echo "\n<p class='err'>Fehler bei Lage mit HsNr.</p>";
	if ($dbg > 2) {echo "\n<p class='dbg'>SQL=<br>".$sqll."<br>$1 = gml_id = '".$gmlid."'</p>";}
}
$zhsnr=0;
while($rowl = pg_fetch_assoc($resl)) { // LOOP: Lagezeilen
	$zhsnr++;
	$ltyp=$rowl["ltyp"]; // Lagezeilen-Typ
	$skey=$rowl["lage"]; // Str.-Schluessel
	$snam=htmlentities($rowl["bezeichnung"], ENT_QUOTES, "UTF-8"); // -Name
	$hsnr=$rowl["hausnummer"];
	$hlfd=$rowl["laufendenummer"];
	$gemeinde=$rowl["gemeinde"];
	$gmllag=$rowl["gmllag"];

	if ($zhsnr === 1) {
		echo "\n<tr>"
			."\n\t<td class='li' title='Lage mit Hausnummer oder Pseudonummer'>Adresse</td>"
			."\n\t<td class='fett'>";
	}
	echo "\n\t\t<img src='ico/Lage_mit_Haus.png' width='16' height='16' alt=''>&nbsp;".DsKy($skey, 'Stra&szlig;en-*');		
	echo "\n\t\t<a title='Hausnummer' href='alkislage.php?gkz=".$gkz."&amp;gmlid=".$gmllag."&amp;ltyp=".$ltyp.LnkStf()."'>".$snam."&nbsp;".$hsnr;
		if ($ltyp === "p") {echo ", lfd.Nr ".$hlfd;}
	echo "</a>, ".$gemeinde."<br>";
} // Ende Loop Lagezeilen m.H.
if ($zhsnr > 0) {echo "\n\t</td>\n\t<td>&nbsp;</td>\n</tr>";}
pg_free_result($resl);

// Gebäudefunktion
echo "\n<tr>"
	."\n\t<td class='li'>Geb&auml;udefunktion</td>"
	."\n\t<td class='fett'>".DsKy($kfunk, 'Geb&auml;udefunktion-*').$bfunk."</td>"
	."\n\t<td>"
		."\n\t\t<p class='erklk'>'Geb&auml;udefunktion' ist die zum Zeitpunkt der Erhebung vorherrschend funktionale Bedeutung des Geb&auml;udes'</p>"
		."\n\t\t<p class='erkli'>".$dfunk."</p>"
	."</td>"
."\n</tr>";

// Bauweise
if ($baw != "" OR $allefelder) {
	echo "\n<tr>"
	."\n\t<td class='li'>Bauweise</td>"
	."\n\t<td class='fett'>".DsKy($baw, 'Bauweise-*').$bbauw."</td>"
	."\n\t<td>"
		."\n\t\t<p class='erklk'>'Bauweise' ist die Beschreibung der Art der Bauweise.</p>"
		."\n\t\t<p class='erkli'>".$dbauw."</p>"
	."\n\t</td>\n</tr>";
}

// Geschosse
if ($aog != "" OR $allefelder) {
	echo "\n<tr>"
	."\n\t<td class='li'>Geschosse</td>"
	."\n\t<td class='fett'>".$aog."</td>"
	."\n\t<td>"
		."\n\t\t<p class='erklk'>Anzahl oberirdischer Geschosse.</p>"
	."\n\t</td>\n</tr>";
}

// U-Geschosse
if ($aug != "" OR $allefelder) {
	echo "\n<tr>"
	."\n\t<td class='li'>U-Geschosse</td>"
	. "\n\t<td class='fett'>".$aug."</td>"
	."\n\t<td>"
		."\n\t\t<p class='erklk'>Anzahl unterirdischer Geschosse.</p>"
	."\n\t</td>\n</tr>";
}

// Hochhaus
if ($hoh != "" OR $allefelder) {
	echo "\n<tr>"
	."\n\t<td class='li'>Hochhaus</td>"
	."\n\t<td class='fett'>".$hoh."</td>"
	."\n\t<td>"
		."\n\t\t<p class='erklk'>'Hochhaus' ist ein Geb&auml;ude, das nach Geb&auml;udeh&ouml;he und Auspr&auml;gung als Hochhaus zu bezeichnen ist. F&uuml;r Geb&auml;ude im Geschossbau gilt dieses i.d.R. ab 8 oberirdischen Geschossen, f&uuml;r andere Geb&auml;ude ab einer Geb&auml;udeh&ouml;he von 22 m."
	."\n\t</td>\n</tr>";
}

// Lage zur Erdoberfläche
if ($ofl != "" OR $allefelder) {
	echo "\n<tr>"
	."\n\t<td class='li'>Lage zur Erdoberfl&auml;che</td>"
	."\n\t<td class='fett'>".DsKy($ofl, '* f&uuml;r Lage zur Erdoberfl&auml;che').$oflv."</td>"
	."\n\t<td>"
		."\n\t\t<p class='erklk'>'Lage zur Erdoberfl&auml;che' ist die Angabe der relativen Lage des Geb&auml;udes zur Erdoberfl&auml;che. Diese Attributart wird nur bei nicht ebenerdigen Geb&auml;uden gef&uuml;hrt.<br>"
		."\n\t\t<p class='erkli'>".$ofld."</p>"
	."\n\t</td>\n</tr>";
}

// Dachgeschossausbau, Spalte dokumentation ist immer leer
if ($dga != "" OR $allefelder) {
	echo "\n<tr>"
	."\n\t<td class='li'>Dachgeschossausbau</td>"
	."\n\t<td class='fett'>".DsKy($dga, '* Dachgeschossausbau').$dgav."</td>"
	."\n\t<td>"
		."\n\t\t<p class='erklk'>'Dachgeschossausbau' ist ein Hinweis auf den Ausbau bzw. die Ausbauf&auml;higkeit des Dachgeschosses."
	."\n\t</td>\n</tr>";
}

// Zustand
if ($zus != "" OR $allefelder) {
	echo "\n<tr>"
	."\n\t<td class='li'>Zustand</td>"
	."\n\t<td class='fett'>";
	echo DsKy($zus, 'Zustand-*').$zusv."</td>"
	."\n\t<td>"
		."\n\t\t<p class='erklk'>'Zustand' beschreibt die Beschaffenheit oder die Betriebsbereitschaft von 'Geb&auml;ude'. Diese Attributart wird nur dann optional gef&uuml;hrt, wenn der Zustand des Geb&auml;udes vom nutzungsf&auml;higen Zustand abweicht.</p>"
		."\n\t\t<p class='erkli'>".$zusd."</p>"
	."\n\t</td>\n</tr>";
}

// Weitere Gebäudefunktionen
if ($wgf != "" OR $allefelder) { // ... ist ein Array
	echo "\n<tr>"
	."\n\t<td class='li'>Weitere Geb&auml;udefunktionen</td>"
	."\n\t<td>";
	if ($wgf != "") { // Kommagetrennte Liste aus Array
		$sqlw="SELECT wert, beschreibung, dokumentation FROM ax_gebaeudefunktion WHERE wert IN ( $1 ) ORDER BY wert;";
		$v = array($wgf);
		$resw = pg_prepare($con, "", $sqlw);
		$resw = pg_execute($con, "", $v);
		if (!$resw) {
			echo "\n<p class='err'>Fehler bei Geb&auml;ude - weitere Funktion.</p>";
			if ($dbg > 2) {echo "\n<p class='dbg'>SQL=<br>".$sqlw."<br>$1 = Werteliste = '".$wgf."'</p>";}
		}
		$zw=0;
		while($roww = pg_fetch_assoc($resw)) { // LOOP Funktion
			if ($zw > 0) {echo "<br>";}
			echo DsKy($roww["wert"], 'Geb&auml;udefunktionen-*')."<span title='".$roww["dokumentation"]."'>".$roww["beschreibung"]."</span>";
			$zw++;
	   }
	   pg_free_result($resw);
	}
	echo "</td>"
	."\n\t<td>"
		."\n\t\t<p class='erklk'>'Weitere Geb&auml;udefunktion' ist die Funktion, die ein Geb&auml;ude neben der dominierenden Geb&auml;udefunktion hat."
	."\n\t</td>\n</tr>";
}

// Dachform, Spalte dokumentation ist immer leer
if ($daf != "" OR $allefelder) {
	echo "\n<tr>"
	."\n\t<td class='li'>Dachform</td>"
	."\n\t<td class='fett'>".DsKy($daf, 'Dachform-*').$dach."</td>"
	."\n\t<td>"
		."\n\t\t<p class='erklk'>'Dachform' beschreibt die charakteristische Form des Daches."
	."\n\t</td>\n</tr>";
}

// Objekthöhe
if ($hho != "" OR $allefelder) {
	echo "\n<tr>"
	."\n\t<td class='li'>Objekth&ouml;he</td>"
	."\n\t<td class='fett'>".$hho."</td>"
	."\n\t<td>"
		."\n\t\t<p class='erklk'>'Objekth&ouml;he' ist die H&ouml;hendifferenz in [m] zwischen dem h&ouml;chsten Punkt der Dachkonstruktion und der festgelegten Gel&auml;ndeoberfl&auml;che des Geb&auml;udes."
	."\n\t</td>\n</tr>";
}

// Geschossfläche
if ($gfl != "" OR $allefelder) {
	echo "\n<tr>"
	."\n\t<td class='li'>Geschossfl&auml;che</td>"
	."\n\t<td class='fett'>";
	if ($gfl != "") {echo $gfl." m&#178;";}
	echo "</td>"
	."\n\t<td>"
		."\n\t\t<p class='erklk'>'Geschossfl&auml;che' ist die Geb&auml;udegeschossfl&auml;che in [qm]."
	."\n\t</td>\n</tr>";
}

// Grundfläche
if ($grf != "" OR $allefelder) {
	echo "\n<tr>"
	."\n\t<td class='li'>Grundfl&auml;che</td>"
	."\n\t<td class='fett'>";
	if ($grf != "") {echo $grf." m&#178;";}
	echo "\n\t<td>"
		."\n\t\t<p class='erklk'>'Grundfl&auml;che' ist die Geb&auml;udegrundfl&auml;che in [qm]."
	."\n\t</td>\n</tr>";
}

// Umbauter Raum
if ($ura != "" OR $allefelder) {
	echo "\n<tr>"
	."\n\t<td class='li'>Umbauter Raum</td>"
	."\n\t<td class='fett'>".$ura."</td>"
	."\n\t<td>"
		."\n\t\t<p class='erklk'>'Umbauter Raum' ist der umbaute Raum [Kubikmeter] des Geb&auml;udes."
	."\n\t</td>\n</tr>";
}

// Baujahr
if ($bja != "" OR $allefelder) {
	echo "\n<tr>"
	."\n\t<td class='li'>Baujahr</td>"
	."\n\t<td class='fett'>".$bja."</td>"
	."\n\t<td>"
		."\n\t\t<p class='erklk'>'Baujahr' ist das Jahr der Fertigstellung oder der baulichen Ver&auml;nderung des Geb&auml;udes."
	."\n\t</td>\n</tr>";
}

// Dachart
if ($daa != "" OR $allefelder) {
	echo "\n<tr>"
	."\n\t<td class='li'>Dachart</td>"
	."\n\t<td class='fett'>".$daa."</td>"
	."\n\t<td>"
		."\n\t\t<p class='erklk'>'Dachart' gibt die Art der Dacheindeckung (z.B. Reetdach) an."
	."\n\t</td>\n</tr>";
}

// D a t e n e r h e b u n g  (Qualität der Einmessung)
$sqle ="SELECT g.gml_id, e.wert, e.beschreibung, e.dokumentation FROM ax_gebaeude g 
LEFT JOIN ax_datenerhebung e ON cast(e.wert AS varchar) = any(g.herkunft_source_source_ax_datenerhebung) 
WHERE g.gml_id= $1 AND g.endet IS NULL;";
$v = array($gmlid);
$rese = pg_prepare($con, "", $sqle);
$rese = pg_execute($con, "", $v);
if (!$rese) {
	echo "\n<p class='err'>Fehler bei Datenerhebung.<br>".pg_last_error()."</p>";
	if ($dbg > 2) {echo "\n<p class='dbg'>SQL=<br>".$sqle."<br>$1 = gml_id = '".$gmlid."'</p>";}
}
while($rowe = pg_fetch_assoc($rese)) { // Schleife weil array-Feld, meist aber leer
	$erheb =$rowe["wert"];
	$berheb=$rowe["beschreibung"];
	$derheb=$rowe["dokumentation"]; // immer leer, oder
	if ($derheb == '' AND $erheb != '') { // Wert ohne Doku
		if ( $erheb >= 2000) { // selbst was dazu sagen
			$derheb = 'nicht eingemessenes Geb&auml;ude'; // so hieß das in der ALK
		}
	}
	if ($erheb != "" OR $allefelder) {
		echo "\n<tr>"
		."\n\t<td class='li'>Datenerhebung</td>"
		."\n\t<td class='fett'>".DsKy($erheb, 'Datenerhebung-*').$berheb."</td>"
		."\n\t<td>"
			."\n\t\t<p class='erklk'>'Datenerhebung' beschreibt Qualit&auml;tsangaben, Herkunft.</p>"
			."\n\t\t<p class='erkli'>".$derheb."</p>"
		."</td>\n</tr>";
	}
}
echo "\n</table>";
pg_free_result($rese);

$gfla=$rowg["gebflae"]; // bei Flurstck. gebraucht
pg_free_result($resg);

echo "\n\n<h3><img src='ico/Flurstueck.png' width='16' height='16' alt=''> Flurst&uuml;cke</h3>"
."\n<p>.. auf dem das Geb&auml;ude steht. Ermittelt durch Verschneidung der Geometrie.</p>";

// F l u r s t ü c k
$sqlf ="SELECT f.gml_id, round(st_area(ST_Intersection(g.wkb_geometry,f.wkb_geometry))::numeric,2) AS schnittflae, 
st_within(g.wkb_geometry,f.wkb_geometry) as drin, o.gemarkungsnummer, o.bezeichnung, f.flurnummer, f.zaehler, f.nenner 
FROM ax_gebaeude g, ax_flurstueck f LEFT JOIN ax_gemarkung o ON f.land=o.land AND f.gemarkungsnummer=o.gemarkungsnummer ".UnqKatAmt("f","o")
."WHERE g.gml_id= $1 AND f.endet IS NULL and g.endet IS NULL 
AND st_intersects(g.wkb_geometry,f.wkb_geometry) = true ORDER BY schnittflae DESC;";

$v=array($gmlid);
$resf=pg_prepare($con, "", $sqlf);
$resf=pg_execute($con, "", $v);
if (!$resf) {
	echo "\n<p class='err'>Fehler bei FS-Verschneidung.</p>";
	if ($dbg > 2) {echo "\n<p class='err'>SQL=<br>".$sqlf."<br>$1 = gml_id = '".$gmlid."'</p>";}
}

echo "\n<hr>\n<table class='geb'>"
."\n<tr>"
	."\n\t<td class='heads fla' title='Schnittfl&auml;che zwischen Flurst&uuml;ck und Geb&auml;ude'><img src='ico/sortd.png' width='10' height='10' alt='' title='Sortierung (absteigend)'>Fl&auml;che</td>"
	."\n\t<td class='head' title='Verh&auml;ltnis Geb&auml;udefl&auml;che zur Flurstücksfl&auml;che'>Verh&auml;ltnis</td>"
	."\n\t<td class='head' title='Flurst&uuml;ckskennzeichen Ortsteil'>Gemarkung</td>"
	."\n\t<td class='head' title='Flurst&uuml;ckskennzeichen Flur-Nummer'>Flur</td>"
	."\n\t<td class='heads fsnr' title='Flurst&uuml;ckskennzeichen Flurst&uuml;cks-Nummer'>Flurst&uuml;ck</td>"
	."\n\t<td class='head nwlink' title='Flurst&uuml;cks-Nachweis'>weitere Auskunft</td>"
."\n</tr>";

while($rowf = pg_fetch_assoc($resf)) {
	$fgml=$rowf["gml_id"];
	$drin=$rowf["drin"];
	$schni=$rowf["schnittflae"];
	$flur= $rowf["flurnummer"];
	$fskenn=$rowf["zaehler"];
	if ($rowf["nenner"] != "") { $fskenn.="/".$rowf["nenner"];}

	// 3 Fälle:
	if ($drin === "t") { // Geb. komplett in FS
		$gstyle="gin"; // siehe .css	
		$f1=number_format($schni,2,",",".") . " m&#178;";
		$f2="vollst&auml;ndig";
	} else {
		if ($schni === "0.00") { // Gebäude angrenzend (Grenzbebauung)
			$gstyle="gan";
			$f1="&nbsp;";
			$f2="angrenzend";
		} else { // Teile des Geb. auf dem FS
			$gstyle="gtl";
			$f1=number_format($schni,2,",",".") . " m&#178;";
			$f2="teilweise";
		}
	}
	echo "\n<tr>"
		."\n\t<td class='fla'>".$f1."</td>"
		."\n\t<td class='".$gstyle."'>".$f2."</td>"
		."\n\t<td>".DsKy($rowf["gemarkungsnummer"], 'Gemarkungsnummer').$rowf["bezeichnung"]."</td>"
		."\n\t<td>".$flur."</td>"
		."\n\t<td class='fsnr'><span class='wichtig'>".$fskenn."</span></td>";

	echo "\n\t<td class='nwlink noprint'>" // Link FS
		."\n\t\t<a title='Flurst&uuml;ck' href='alkisfsnw.php?gkz=".$gkz."&amp;gmlid=".$fgml.LnkStf()
		."'>Flurst&uuml;ck&nbsp;<img src='ico/Flurstueck_Link.png' width='16' height='16' alt=''></a>"
		."\n\t</td>"
	."\n</tr>";
}

$gfla=number_format($gfla,2,",",".") . " m&#178;";
echo "\n<tr>\n\t<td class='fla sum'>".$gfla."</td>\n\t<td>Geb&auml;udefl&auml;che</td>\n\t<td></td>\n</tr>";
echo "\n</table>";

echo "<div class='buttonbereich noprint'>\n<hr>"
	."\n\t<a title='zur&uuml;ck' href='javascript:history.back()'><img src='ico/zurueck.png' width='16' height='16' alt='zur&uuml;ck'></a>&nbsp;";
if ($PrntBtn==true){echo "\n\t<a title='Drucken' href='javascript:window.print()'><img src='ico/print.png' width='16' height='16' alt='Drucken'></a>&nbsp;";}
echo "\n</div>";

footer($gmlid, selbstverlinkung()."?", "");
?>
</body>
</html>

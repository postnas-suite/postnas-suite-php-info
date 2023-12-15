<?php
/*	alkisausk.php

	ALKIS-Auskunft
	Author: Frank Jäger, Kommunales Rechenzentrum Minden-Ravensberg/Lippe (Lemgo)

	Dies Programm wird aus dem Mapserver-Template (FeatureInfo) aufgerufen.
	Parameter: &gkz, &gml_id (optional &id)
	Dies Programm gibt einen kurzen Überblick zum Flurstück, z.B. Eigentuemer ohne Adresse
	Für detaillierte Angaben wird zum GB- oder FS-Nachweis verlinkt.
	Siehe auch alkisinlayausk.php - eine Variante für den Einbau in einen iFrame

	Version:
	2016-02-24 Version für norGIS-ALKIS-Import
	....
	2020-12-03 function selbstverlinkung() statt $_SERVER['PHP_SELF']
	2020-12-15 Input-Validation und Strict Comparisation (===)
	2022-01-13 Neue Functions LnkStf(), DsKy()
	2022-07-05 PHP 8.1: Connection verwenden bei "pg_prepare" und "pg_execute", keine NULL-Werte in String-Functions verwenden

H i n w e i s :  Dies Modul wird beim Entwickler nicht mehr produktiv eingesetzt.
		Statt dessen wird "alkisinlayausk.php" verwendet um von einer WMS-FeatureInfo in ein Fenster überzuleiten.
		Mangels Praxiseinsatz bleiben Fehler möglicherweise unerkannt.
*/
ini_set("session.cookie_httponly", 1);
session_start();
$cntget = extract($_GET);
if (isset($gkz)) {
	if (!preg_match('#^[0-9]{3}$#', $gkz)) {die("Eingabefehler gkz");}
} else {
	die("Fehlender Parameter");
}
include "alkis_conf_location.php";
include "alkisfkt.php";

$keys = isset($_GET["showkey"]) ? $_GET["showkey"] : "n";
if ($keys === "j") {$showkey=true;} else {$showkey=false;}
echo <<<END
<!doctype html>
<html lang="de">
<head>
	<meta charset="utf-8">
	<link rel="stylesheet" type="text/css" href="alkisauszug.css">
	<link rel="shortcut icon" type="image/x-icon" href="ico/Flurstueck.ico">
	<title>ALKIS-Auskunft</title>
</head>
<body>
END;

$erlaubnis = darf_ich(); if ($erlaubnis === 0) { die('<p class="stop1">Abbruch</p></body>'); }
$dbg=$debug;
$con = pg_connect($dbconn." options='--application_name=ALKIS-Auskunft_alkisausk.php'");
if (!$con) {echo "<br>Fehler beim Verbinden der DB.\n<br>";}

// F L U R S T Ü C K 
$sql ="SELECT f.flurnummer, f.zaehler, f.nenner, f.amtlicheflaeche, g.gemarkungsnummer, g.bezeichnung, f.gemeindezugehoerigkeit_regierungsbezirk, f.gemeindezugehoerigkeit_kreis, f.gemeindezugehoerigkeit_gemeinde
FROM ax_flurstueck f LEFT JOIN ax_gemarkung g ON f.land=g.land AND f.gemarkungsnummer=g.gemarkungsnummer
WHERE f.gml_id= $1 AND f.endet IS NULL AND g.endet IS NULL;";

$v = array($gmlid);
$res = pg_prepare($con, "", $sql);
$res = pg_execute($con, "", $v);
if (!$res) {
	echo "\n<p class='err'>Fehler bei Flurstuecksdaten.</p>";
	if ($dbg > 2) {echo "\n<p class='err'>SQL=<br>".$sql."<br>$1 = gml_id = '".$gmlid."'</p>";}
}

if ($row = pg_fetch_array($res)) {
	$gemkname=htmlentities($row["bezeichnung"], ENT_QUOTES, "UTF-8");
	$gmkgnr=$row["gemarkungsnummer"];
	$flurnummer=$row["flurnummer"];
	$flstnummer=$row["zaehler"];
	$nenner=$row["nenner"];
	if ($nenner > 0) $flstnummer.="/".$nenner; // BruchNr
	$flae=$row["amtlicheflaeche"];
	$flae=number_format($flae,0,",",".") . " m&#178;";
	$fsreg=$row["gemeindezugehoerigkeit_regierungsbezirk"]; // Filter Zuständigkeit
	$fskrs=$row["gemeindezugehoerigkeit_kreis"];
	$fsgem=$row["gemeindezugehoerigkeit_gemeinde"];
} else {
	echo "\n<p class='err'>Kein Treffer fuer gml_id=".$gmlid."</p>";
}

// Balken
echo "\n<p class='fsausk'>ALKIS-Auskunft Flurst&uuml;ck-&Uuml;bersicht ".$gmkgnr."-".$flurnummer."-".$flstnummer."</p>";

// Prüfung der Gebiets-Berechtigung bei gemeinsam genutzten Datenbanken (Kreis und Gemeinde)
// Für das gkz (z.B. aus dem Mapfile-Namen) wird in der Konfiguration ein Filter gesetzt.
if ( ($filtkreis != '' and $filtkreis != $fskrs) or ($filtgem != '' and $filtgem != $fsgem) ) {
	// Einer der gesetzten Filter passt nicht
	if ($dbg > 2) {
		echo "\n<p class='err'>Filter Kreis='".$filtkreis."', Gemeinde='".$filtgem."'</p>"
		."\n<p class='err'>Flstk. Kreis='".$fskrs."', Gemeinde='".$fsgem."'</p>";
	}
	echo "\n<br><p class='stop1'>Zugriff nicht erlaubt</p>
	\n<br><p class='stop2'>Dies Flurst&uuml;ck liegt ausserhalb der zust&auml;ndigen Stadt oder Gemeinde.</p>\n</body>\n</html>";
	pg_free_result($res);
	exit;
}

echo "\n<table class='outer'>\n<tr><td>"
	."\n<h1>ALKIS-Auskunft</h1>"
	."\n<h2><img src='ico/Flurstueck.png' width='16' height='16' alt=''> Flurst&uuml;ck - &Uuml;bersicht</h2>";
echo "</td><td align='right'>"
	."<img src='pic/AAA.gif' alt=''>"
."</td></tr></table>";

echo "\n<table class='outer'>\n<tr>\n<td>"
	."\n\t<table class='kennzfs' title='Flurst&uuml;ckskennzeichen'>\n\t<tr>"
	."\n\t\t<td class='head'>Gemarkung</td>\n\t\t<td class='head'>Flur</td>\n\t\t<td class='head'>Flurst-Nr.</td>\n\t</tr>"
	."\n\t<tr>\n\t\t<td title='Gemarkung'>".DsKy($gmkgnr, 'Gemarkungsnummer').$gemkname."</td>"
	."\n\t\t<td title='Flurnummer'>".$flurnummer."</td>"
	."\n\t\t<td title='Flurst&uuml;cksnummer (Z&auml;hler / Nenner)'><span class='wichtig'>".$flstnummer."</span></td>\n\t</tr>"
	."\n\t</table>"
."\n</td>\n<td>"
."\n\t<p class='nwlink'>weitere Auskunft:<br>";

// Flurstücksnachweis (o. Eigent.)
echo "\n\t<a href='alkisfsnw.php?gkz=".$gkz."&amp;gmlid=".$gmlid."&amp;eig=n".LnkStf()
	."' title='Flurst&uuml;cksnachweis, alle Flurst&uuml;cksdaten'>Flurst&uuml;ck "
	."<img src='ico/Flurstueck_Link.png' width='16' height='16' alt=''>"
."</a><br>";

// FS- u. Eigent.-NW
echo "\n\t\t<a href='alkisfsnw.php?gkz=".$gkz."&amp;gmlid=".$gmlid."&amp;eig=j".LnkStf()
	."' title='Flurst&uuml;ck mit Eigent&uuml;mer'>Flurst&uuml;ck mit Eigent&uuml;mer "
	."<img src='ico/Flurstueck_Link.png' width='16' height='16' alt=''>"
."</a><br>";

// FS-Historie
echo "\n\t\t<a href='alkisfshist.php?gkz=".$gkz."&amp;gmlid=".$gmlid.LnkStf()
	."' title='Vorg&auml;nger des Flurst&uuml;cks'>Historie "
	."<img src='ico/Flurstueck_Historisch.png' width='16' height='16' alt=''>"
."</a><br>";

// Gebäude-NW
echo "\n\t\t<a href='alkisgebaeudenw.php?gkz=".$gkz."&amp;gmlid=".$gmlid.LnkStf()
	."' title='Geb&auml;udenachweis'>Geb&auml;ude "
	."<img src='ico/Haus.png' width='16' height='16' alt=''>"
."</a>"
. "\n\t</p>\n</td>";

// Lagebezeichnung MIT Hausnummer (Adresse)
$sql ="SELECT DISTINCT l.gml_id, s.gml_id AS kgml, l.gemeinde, l.lage, l.hausnummer, s.bezeichnung 
FROM ax_flurstueck f JOIN ax_lagebezeichnungmithausnummer l ON l.gml_id=ANY(f.weistauf)
LEFT JOIN ax_lagebezeichnungkatalogeintrag s ON l.kreis=s.kreis AND l.gemeinde=s.gemeinde AND l.lage=s.lage 
WHERE f.gml_id= $1 AND f.endet IS NULL AND l.endet IS NULL AND s.endet IS NULL ORDER BY l.gemeinde, l.lage, l.hausnummer;";

$v = array($gmlid);
$res = pg_prepare($con, "", $sql);
$res = pg_execute($con, "", $v);
if (!$res) {
	echo "\n<p class='err'>Fehler bei Lagebezeichnung mit Hausnummer.</p>";
	if ($dbg > 2) {echo "\n<p class='err'>SQL=<br>".$sql."<br>$1 = gml_id = '".$gmlid."'</p>";}
}
$j=0;
while($row = pg_fetch_array($res)) {
	$sname = htmlentities($row["bezeichnung"], ENT_QUOTES, "UTF-8"); // Str.-Name
	echo "\n<tr>\n\t"
		."\n\t<td class='lr'>".$sname."&nbsp;".$row["hausnummer"]."</td>"
		."\n\t<td>\n\t\t<p class='nwlink noprint'>"
			."\n\t\t\t<a title='Lagebezeichnung mit Hausnummer' href='alkislage.php?gkz=".$gkz."&amp;ltyp=m&amp;gmlid=".$row["gml_id"]."'>Lage "
			."<img src='ico/Lage_mit_Haus.png' width='16' height='16' alt=''></a>&nbsp;"
			."\n\t\t\t<a href='alkisstrasse.php?gkz=".$gkz."&amp;gmlid=".$row["kgml"]
			."' title='Stra&szlig;e'>Stra&szlig;e <img src='ico/Strassen.png' width='16' height='16' alt=''></a>"
		."\n\t\t</p>\n\t</td>"
	."\n</tr>";
	$j++;
}
echo "\n</tr>\n</table>";

// Flurstücksfläche
echo "\n<p class='fsd'>Flurst&uuml;cksfl&auml;che: <b>".$flae."</b></p>";

// G R U N D B U C H
echo "\n<h2><img src='ico/Grundbuch_zu.png' width='16' height='16' alt=''> Grundbuch</h2>";
// ALKIS: FS >istgebucht> GS >istBestandteilVon> GB.
$sql ="SELECT b.gml_id, b.bezirk, b.buchungsblattnummermitbuchstabenerweiterung as blatt, b.blattart, wa.beschreibung AS blattartv, 
s.gml_id AS s_gml, s.buchungsart, s.laufendenummer, s.zaehler, s.nenner, z.bezeichnung, wb.beschreibung AS bart 
FROM ax_flurstueck f 
JOIN ax_buchungsstelle s ON f.istgebucht=s.gml_id 
JOIN ax_buchungsblatt b ON s.istbestandteilvon=b.gml_id 
LEFT JOIN ax_buchungsblattbezirk z ON z.land=b.land AND z.bezirk=b.bezirk 
LEFT JOIN ax_blattart_buchungsblatt wa ON b.blattart = wa.wert
LEFT JOIN ax_buchungsart_buchungsstelle wb ON s.buchungsart = wb.wert
WHERE f.gml_id= $1 AND f.endet IS NULL AND s.endet IS NULL AND b.endet IS NULL AND z.endet IS NULL 
ORDER BY b.bezirk, b.buchungsblattnummermitbuchstabenerweiterung, s.laufendenummer;";

$v = array($gmlid);
$resg = pg_prepare($con, "", $sql);
$resg = pg_execute($con, "", $v);
if (!$resg) {
	echo "\n<p class='err'>Keine Buchungen.</p>";
	if ($dbg > 2) {echo "\n<p class='err'>SQL=<br>".$sql."<br>$1 = gml_id = '".$gmlid."'</p>";}
}

$j=0; // Z.Blatt
while($rowg = pg_fetch_array($resg)) {
	$beznam=$rowg["bezeichnung"];
	echo "\n<table class='outer'>\n<tr>\n<td>";
		$blattkey=$rowg["blattart"];
		$blattart=$rowg["blattartv"];
		if ($blattkey === 1000) {
			echo "\n\t<table class='kennzgb' title='Bestandskennzeichen'>";
		} else {
			echo "\n\t<table class='kennzgbf' title='Bestandskennzeichen'>"; // dotted
		}
			echo "\n\t<tr>"
				."\n\t\t<td class='head'>Bezirk</td>"
				."\n\t\t<td class='head'>".$blattart."</td>"
				."\n\t\t<td class='head'>Lfd-Nr.</td>"
				."\n\t\t<td class='head'>Buchungsart</td>"
			."\n\t</tr>";
			echo "\n\t<tr>"
				."\n\t\t<td title='Grundbuchbezirk'>".DsKy($rowg["bezirk"], 'Grundbuch-Bezirk-*').$beznam."</td>"
				."\n\t\t<td title='Grundbuch-Blatt'><span class='wichtig'>".$rowg["blatt"]."</span></td>"
				."\n\t\t<td title='Bestandsverzeichnis-Nummer (BVNR, Grundst&uuml;ck)'>".$rowg["laufendenummer"]."</td>"
				."\n\t\t<td title='Buchungsart'>".DsKy($rowg["buchungsart"], 'Buchungsart-*').$rowg["bart"]."</td>"
			."\n\t</tr>"
		."\n\t</table>";

		if ($rowg["zaehler"] != "") {
			echo "\n<p class='ant'>".$rowg["zaehler"]."/".$rowg["nenner"]."&nbsp;Anteil am Flurst&uuml;ck</p>";
		}
		echo "\n</td>\n<td>"
		."\n\t<p class='nwlink'>weitere Auskunft:<br>"
			."\n\t\t<a href='alkisbestnw.php?gkz=".$gkz."&amp;gmlid=".$rowg[0].LnkStf()
			."' title='Grundbuchnachweis'>".$blattart." <img src='ico/GBBlatt_link.png' width='16' height='16' alt=''></a>"
		."\n\t</p>"
	."\n</td>\n</tr>\n</table>";

	// E I G E N T Ü M E R
	if ($blattkey === 5000) { // Schlüssel Blatt-Art
		echo "\n<p>Keine Angaben zum Eigentum bei fiktivem Blatt</p>\n"
		."\n<p>Siehe weitere Grundbuchbl&auml;tter mit Rechten an dem fiktiven Blatt.</p>";
	} else { // kein Eigent. bei fiktiv. Blatt
		// Ausgabe Name in Function
		$n = eigentuemer($rowg["gml_id"], false, true); // ohne Adressen

		if ($n === 0) { // keine NamNum, kein Eigent.
			echo "\n<p class='err'>Keine Eigent&uuml;mer gefunden.</p>"
			."\n<p class='err'>Bezirk ".$rowg["bezirk"]." Blatt ".$rowg["blatt"]." Blattart ".$blattkey." (".$blattart.")</p>";
		}
	}
	$j++;
}
if ($j === 0) {echo "\n<p class='err'>Keine Buchungen gefunden.</p>";}
echo "\n<hr>";

footer($gmlid, selbstverlinkung()."?", "");

?>
</body>
</html>
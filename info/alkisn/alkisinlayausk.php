<?php
/*	alkisinlayausk.php

	ALKIS-Auskunft
	Author: Frank Jäger, Kommunales Rechenzentrum Minden-Ravensberg/Lippe (Lemgo)

	Dies Programm wird in einen iFrame im Mapserver-Template der FeatureInfo geladen.
	Parameter: &gkz, &gml_id
	Dies Programm gibt einen kurzen Überblick zum Flurstück, z.B. Eigentümer ohne Adresse.
	Für detaillierte Angaben wird zum GB- oder FS-Nachweis verlinkt.
	Dies ist eine Variante von alkisausk.php welches als vollständige Seite aufgerufen wird.

	Version:
	2016-02-24 Version für norGIS-ALKIS-Import
	....
	2020-02-03 Fenster-Weite
	2020-02-20 Authentifizierung ausgelagert in Function darf_ich()
	2020-10-14 include ohne Klammer
	2020-12-15 Input-Validation und Strict Comparisation (===)
	2021-03-09 Link zum Gebäudenachweis auch mit "Bauwerke" betiteln
	2021-12-09 Neue Parameter: $katAmtMix (Kataster-Amt-Mix)
	2022-07-05 PHP 8.1: Connection verwenden bei "pg_prepare" und "pg_execute", keine NULL-Werte in String-Functions verwenden
*/
ini_set("session.cookie_httponly", 1);
session_start();
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

include "alkis_conf_location.php";
include "alkisfkt.php";

// Wert für "width=" aus der Function "imFenster" synchron halten mit "@media screen body width" aus "alkisauszug.css"
echo <<<END
<!doctype html>
<html lang="de">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<link rel="stylesheet" type="text/css" href="alkisauszug.css">
	<link rel="shortcut icon" type="image/x-icon" href="ico/Flurstueck.ico">
	<title>ALKIS-Auskunft</title>
	<script type="text/javascript" language="JavaScript">
		function imFenster(dieURL) {
			var link = encodeURI(dieURL);
			window.open(link,'','left=10,top=10,width=750,height=840,resizable=yes,menubar=no,toolbar=no,location=no,status=no,scrollbars=yes');
		}
	</script>
</head>
<body class ="mbfi">
END;

$erlaubnis = darf_ich(); if ($erlaubnis === 0) { die('<p class="stop1">Abbruch</p></body>'); }
$dbg=$debug;

// Body des Inlay muss in Mapbender-Feature-Info-PopUp passen. Kleiner als 750 aus css.
$con = pg_connect($dbconn." options='--application_name=ALKIS-Auskunft_alkisinlayausk.php'");
if (!$con) {echo "<br>Fehler beim Verbinden der DB.\n<br>";}

// F L U R S T Ü C K
$sql ="SELECT f.flurnummer, f.zaehler, f.nenner, f.amtlicheflaeche, g.gemarkungsnummer, g.bezeichnung, f.gemeindezugehoerigkeit_regierungsbezirk, f.gemeindezugehoerigkeit_kreis, f.gemeindezugehoerigkeit_gemeinde, f.istgebucht
FROM ax_flurstueck f LEFT JOIN ax_gemarkung g ON f.land=g.land AND f.gemarkungsnummer=g.gemarkungsnummer ".UnqKatAmt("f","g")
."WHERE f.gml_id= $1 AND f.endet IS NULL AND g.endet IS NULL;";

$v = array($gmlid);
$res = pg_prepare($con, "", $sql);
$res = pg_execute($con, "", $v);
if (!$res) {
	echo "\n<p class='err'>Fehler bei Flurstuecksdaten.</p>";
	if ($dbg > 2) {echo "\n<p class='err'>SQL=<br>".htmlentities(str_replace("$1", "'".$gmlid."'", $sql), ENT_QUOTES, "UTF-8")."</p>";}
}
if ($dbg > 0) {
	$zeianz=pg_num_rows($res);
	if ($zeianz > 1){
		echo "\n<p class='err'>Die Abfrage liefert mehr als ein (".$zeianz.") Flurst&uuml;cks-Objekt!</p>";
		if ($dbg > 2) {echo "\n<p class='dbg'>SQL=<br>".htmlentities(str_replace("$1","'".$gmlid."'",$sql), ENT_QUOTES, "UTF-8")."</p>";}
	}
}
if ($row = pg_fetch_assoc($res)) {
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
	$gml_buchungsstelle=$row["istgebucht"];
} else {
	echo "\n<p class='err'>Kein Treffer fuer gml_id=".$gmlid."</p>";
}

// Prüfung der Gebiets-Berechtigung bei gemeinsam genutzten Datenbanken (Kreis und Gemeinde)
// Für das gkz (z.B. aus dem Mapfile-Namen) wird in der Konfiguration ein Filter gesetzt.
if ( ($filtkreis != '' and $filtkreis != $fskrs) or ($filtgem != '' and $filtgem != $fsgem) ) {
	// Einer der gesetzten Filter passt nicht
	if ($dbg > 2) {
		echo "\n<p class='err'>Filter Kreis='".$filtkreis."', Gemeinde='".$filtgem."'</p>";
		echo "\n<p class='err'>Flstk. Kreis='".$fskrs."', Gemeinde='".$fsgem."'</p>";
	}
	echo "\n<br><p class='stop1'>Zugriff nicht erlaubt</p>
	\n<br><p class='stop2'>Dies Flurst&uuml;ck liegt ausserhalb der zust&auml;ndigen Stadt oder Gemeinde.</p>\n</body>\n</html>";
	pg_free_result($res);
	exit;
}

// Überschrift ist im umgebenden HTML vorhanden
echo "\n<table class='outer'>"
."\n\t<tr>"
	."\n\t\t<td class='ll'><img src='ico/Flurstueck.png' width='16' height='16' alt=''> Kennzeichen:</td>"
		."\n\t\t<td>"
		."\n\t\t\t<table class='kennzfs' title='Flurst&uuml;ckskennzeichen'>\n\t\t\t\t<tr>"
			."\n\t\t\t\t\t<td class='head'>Gemarkung</td>\n\t\t\t\t\t<td class='head'>Flur</td>\n\t\t\t\t\t<td class='head'>Flurst-Nr.</td>\n\t\t\t\t</tr>"
			."\n\t\t\t\t<tr>\n\t\t\t\t\t<td title='Gemarkung'>".$gemkname."</td>"
			."\n\t\t\t\t\t<td title='Flurnummer'>".$flurnummer."</td>"
			."\n\t\t\t\t\t<td title='Flurst&uuml;cksnummer (Z&auml;hler / Nenner)'><span class='wichtig'>".$flstnummer."</span></td>\n\t\t\t\t</tr>"
		."\n\t\t\t</table>"
	."\n\t\t</td>\n\t\t<td>"
	."\n\t\t\t<p class='nwlink noprint'>weitere Auskunft:<br>";
// Flurstücksnachweis (mit Eigentümer)
echo "\n\t\t\t\t<a href='javascript:imFenster(\"alkisfsnw.php?gkz=".$gkz."&amp;gmlid=".$gmlid."&amp;eig=j\")' "
	."title='Flurst&uuml;cksnachweis'>Flurst&uuml;ck&nbsp;"
	."<img src='ico/Flurstueck_Link.png' width='16' height='16' alt=''>"
."</a><br>";

// FS-Historie
if ($fsHistorie){ // conf
	echo "\n\t\t\t\t<a href='javascript:imFenster(\"alkisfshist.php?gkz=".$gkz."&amp;gmlid=".$gmlid."\")' "
		."title='Vorg&auml;nger des Flurst&uuml;cks'>Historie&nbsp;"
		."<img src='ico/Flurstueck_Historisch.png' width='16' height='16' alt=''>"
	."</a><br>";
}
// Gebäude-NW zum FS
echo "\n\t\t\t\t<a href='javascript:imFenster(\"alkisgebaeudenw.php?gkz=".$gkz."&amp;gmlid=".$gmlid."\")' "
	."title='Geb&auml;ude oder Bauwerke auf oder an diesem Flurst&uuml;ck'>Geb&auml;ude/Bauw.&nbsp;"
	."<img src='ico/Haus.png' width='16' height='16' alt=''>"
."</a>";
echo "\n\t\t\t</p>\n\t\t</td>\n\t</tr>";
pg_free_result($res);

// Lage  M I T  HausNr (Adresse)
$sql ="SELECT DISTINCT s.gml_id AS kgml, l.gml_id, s.bezeichnung, l.hausnummer 
FROM ax_flurstueck f JOIN ax_lagebezeichnungmithausnummer l ON l.gml_id=ANY(f.weistauf)
JOIN ax_lagebezeichnungkatalogeintrag s ON l.kreis=s.kreis AND l.gemeinde=s.gemeinde AND l.lage=s.lage 
WHERE f.gml_id= $1 AND f.endet IS NULL AND l.endet IS NULL AND s.endet IS NULL 
ORDER BY s.bezeichnung, l.hausnummer;";
$v=array($gmlid); // id FS
$res=pg_prepare($con, "", $sql);
$res=pg_execute($con, "", $v);
if (!$res) {
	echo "\n<p class='err'>Fehler bei Lagebezeichnung mit Hausnummer.</p>";
	if ($dbg > 2) {echo "\n<p class='err'>SQL=<br>".htmlentities(str_replace("$1", "'".$gmlid."'", $sql), ENT_QUOTES, "UTF-8")."</p>";}
}
$j=0;
$kgmlalt='';
while($row = pg_fetch_assoc($res)) {
	$sname=htmlentities($row["bezeichnung"], ENT_QUOTES, "UTF-8"); // Str.-Name
	if (substr($sname, strlen($sname) -3, 3) === 'weg') { // Versuch fuer korrekten Satzbau
		$slink=" am ".$sname;
	} else {
		$slink=" an der ".$sname;
	}
	$hsnr=$row["hausnummer"];
	echo "\n\t<tr>"
		."\n\t\t<td class='ll'>";
		if ($j === 0) {echo "<img src='ico/Lage_mit_Haus.png' width='16' height='16' alt='MIT'> Lage:";}
		echo "</td>"
		."\n\t\t<td class='lr'>".$sname."&nbsp;".$hsnr."</td>"
		."\n\t\t<td>\n\t\t\t<p class='nwlink noprint'>";
	$kgml=$row["kgml"]; // Wiederholung vermeiden
	if ($kgml != $kgmlalt) { // NEUE Strasse vor Lage
		$kgmlalt=$kgml; // Katalog GML-ID
		echo "\n\t\t\t\t<a title='Flurst&uuml;cke mit oder ohne Hausnummer".$slink."' "
		."href='javascript:imFenster(\"alkisstrasse.php?gkz=".$gkz."&amp;gmlid=".$kgml."\")'>Stra&szlig;e "
		."<img src='ico/Strassen.png' width='16' height='16' alt='STRA'></a>";
	}
	echo "\n\t\t\t\t<a title='Flurst&uuml;cke und Geb&auml;ude mit Hausnummer ".$hsnr."' "
		."href='javascript:imFenster(\"alkislage.php?gkz=".$gkz."&amp;ltyp=m&amp;gmlid=".$row["gml_id"]."\")'>Lage "
		."<img src='ico/Lage_mit_Haus.png' width='16' height='16' alt='HAUS'></a>&nbsp;"
	."\n\t\t\t</p>\n\t\t</td>\n\t</tr>";
	$j++;
}
pg_free_result($res);

// Lage  O H N E   HausNr
$sql="SELECT DISTINCT s.gml_id AS kgml, l.gml_id, s.bezeichnung, l.unverschluesselt "
	."FROM ax_flurstueck f JOIN ax_lagebezeichnungohnehausnummer l ON l.gml_id=ANY(f.zeigtauf) "
	."LEFT JOIN ax_lagebezeichnungkatalogeintrag s ON l.kreis=s.kreis AND l.gemeinde=s.gemeinde AND l.lage=s.lage "
	."WHERE f.gml_id= $1 AND f.endet IS NULL AND l.endet IS NULL AND s.endet IS NULL ORDER BY s.bezeichnung;";
$v=array($gmlid);
$res=pg_prepare($con, "", $sql);
$res=pg_execute($con, "", $v);
if (!$res) {
	echo "\n<p class='err'>Fehler bei Lagebezeichnung ohne Hausnummer.</p>";
	if ($dbg > 2) {echo "\n<p class='err'>SQL=<br>".htmlentities(str_replace("$1", "'".$gmlid."'", $sql), ENT_QUOTES, "UTF-8")."</p>";}
}
$kgmlalt='';
while($row = pg_fetch_assoc($res)) {
	$sname=htmlentities($row["bezeichnung"], ENT_QUOTES, "UTF-8"); // Str.-Name
	if (substr($sname, strlen($sname) -3, 3) === 'weg') { // Versuch für korrekten Satzbau
		$slink=" am ".$sname;
	} else {
		$slink=" an der ".$sname;
	}

	$gewann=htmlentities($row["unverschluesselt"], ENT_QUOTES, "UTF-8");
	echo "\n\t<tr>";
	if ($sname != "") { // Typ=Strasse
		$ico="Lage_an_Strasse.png";
		echo "\n\t\t<td class='ll'><img src='ico/".$ico."' width='16' height='16' alt='OHNE'> Stra&szlig;e:</td>";
		echo "\n\t\t<td class='lr' title='An Stra&szlig;e aber ohne Hausnummer'>".$sname."&nbsp;</td>";
	} else {
		$ico="Lage_Gewanne.png";
		echo "\n\t\t<td class='ll'><img src='ico/".$ico."' width='16' height='16' alt='Gewanne'> Gewanne:</td>";
		echo "\n\t\t<td class='lr' title='Gewanne'>".$gewann."&nbsp;</td>";
	}
	echo "\n\t\t<td>\n\t\t\t<p class='nwlink noprint'>";
	if ($sname != "") { // Typ=Straße
		$kgml=$row["kgml"]; // Wiederholung vermeiden
		if ($kgml != $kgmlalt) { // NEUE Straße vor Lage-O
			$kgmlalt=$kgml; // Katalog GML-ID
			echo "\n\t\t\t<a class='noprint' title='Flurst&uuml;cke mit oder ohne Hausnummer".$slink."' "
			."href='javascript:imFenster(\"alkisstrasse.php?gkz=".$gkz."&amp;gmlid=".$kgml."\")'>Stra&szlig;e "
			."<img src='ico/Strassen.png' width='16' height='16' alt='STRA'></a>";
		}
		echo "\n\t\t\t<a class='noprint' title='Flurst&uuml;cke ohne Hausnummer".$slink."' "
		."href='javascript:imFenster(\"alkislage.php?gkz=".$gkz."&amp;ltyp=o&amp;gmlid=".$row["gml_id"]."\")'>Lage "
		."<img src='ico/".$ico."' width='16' height='16' alt='OHNE'></a>&nbsp;"
		."\n\t\t</p>\n\t</td>\n</tr>";
	} else { // Typ Gewanne
		echo "\n\t\t\t<a title='Flurst&uuml;cke mit dieser Gewanne als Lagebezeichnung' "
		."href='javascript:imFenster(\"alkislage.php?gkz=".$gkz."&amp;ltyp=o&amp;gmlid=".$row["gml_id"]."\")'>Gewanne "
		."<img src='ico/".$ico."' width='16' height='16' alt='Gewanne'></a>&nbsp;"
		."\n\t\t</p>\n\t\t</td>\n\t</tr>";
	}
}
pg_free_result($res);
echo "\n</table>\n";
echo "\n<p class='fsd'>Flurst&uuml;cksfl&auml;che: <b>".$flae."</b></p>";

// B U C H U N G S S T E L L E N  zum FS
$bartgrp="";	// Buchungsart
$barttypgrp="";	// Buchungsart Typ
if ($gml_buchungsstelle == '') {echo "\n<p class='err'>Keine Buchungstelle zum Flurst&uuml;ck gefunden.</p>";}
echo "\n\n<table class='outer'>";
	$gezeigt=buchung_anzg($gml_buchungsstelle, 'j', true, "", 1); // direkte Buchung anzeigen
	$anzber=ber_bs_zaehl($gml_buchungsstelle, $con); // Ber. Buchg., nur Anzahl
	if ($anzber > 0 ) {
		if ($gezeigt) {
			echo "\n\t<tr>\n\t\t<td colspan='4' title='Komplexe Situationen werden in dieser Vorschau nicht vollst&auml;ndig dargestellt.'><span class='wichtig'>Berechtigte Buchungen siehe Flurst&uuml;ck oder Buchung.</span></td>\n\t</tr>";
		} else {
			$nochmehr=ber_bs_anzg($gml_buchungsstelle, "j", true, "", 1); // wenigstens EINE Buchg. zeigen
			if (count($nochmehr) > 0) { // liefert array, hier nicht weiter verfolgen
				echo "\n\t<tr>\n\t\t<td colspan='4'><span class='wichtig'>Weitere berechtigte Buchungen siehe Flurst&uuml;cksnachweis.</span></td>\n\t</tr>";
			}
		} 
	}
echo "\n</table>\n";

?>
</body>
</html>
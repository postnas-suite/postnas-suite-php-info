<?php
/*	alkisinlaybaurecht.php

	ALKIS-Auskunft
	Author: Frank Jäger, Kommunales Rechenzentrum Minden-Ravensberg/Lippe (Lemgo)

	Inlay für Template: Baurecht
	Ähnlich alkisbaurecht, aber nur Basisdaten, kein Footer und keine Flurstücks-Verschneidung.

	Version:
	2016-02-24 Version für norGIS-ALKIS-Import
	....
	2020-02-03 Fenster-Weite
	2020-02-20 Authentifizierung ausgelagert in Function darf_ich()
	2020-12-15 Input-Validation und Strict Comparisation (===)
	2021-12-09 2021-12-09 Neue Parameter: $katAmtMix (Kataster-Amt-Mix)
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
	<title>ALKIS Bau-, Raum- oder Bodenordnungsrecht</title>
	<link rel="stylesheet" type="text/css" href="alkisauszug.css">
	<link rel="shortcut icon" type="image/x-icon" href="ico/Gericht.ico">
	<script type="text/javascript">
	function imFenster(dieURL) {
		var link = encodeURI(dieURL);
		window.open(link,'','left=30,top=30,width=750,height=800,resizable=yes,menubar=no,toolbar=no,location=no,status=no,scrollbars=yes');
	}
	</script>
</head>
<body style='width: 98%;'>
END;

$erlaubnis = darf_ich(); if ($erlaubnis === 0) { die('<p class="stop1">Abbruch</p></body>'); }
$dbg=$debug;

// Body des Inlay muss in Mapbender-Feature-Info-PopUp passen. Kleiner als 750 aus css.
$con = pg_connect($dbconn." options='--application_name=ALKIS-Auskunft_alkisinlaybaurecht.php'");
if (!$con) echo "\n<p class='err'>Fehler beim Verbinden der DB</p>";

// Keine Spalten, die eine Berechtigungsprüfung nach Gemeinde ermöglichen:
$sql ="SELECT r.ogc_fid, r.name, r.stelle, r.bezeichnung AS rechtbez, a.beschreibung AS adfbez, d.bezeichnung AS stellbez, round(st_area(r.wkb_geometry)::numeric,0) AS flae 
FROM ax_bauraumoderbodenordnungsrecht r 
LEFT JOIN ax_artderfestlegung_bauraumoderbodenordnungsrecht a ON r.artderfestlegung = a.wert
LEFT JOIN ax_dienststelle d ON r.land=d.land AND r.stelle=d.stelle ".UnqKatAmt("r","d")
."WHERE r.gml_id= $1 AND r.endet IS NULL AND d.endet IS NULL;";

$v = array($gmlid);
$res = pg_prepare($con, "", $sql);
$res = pg_execute($con, "", $v);

if (!$res) {
	echo "\n<p class='err'>Fehler bei Baurecht.</p>";
	if ($dbg > 2) {echo "\n<p class='err'>SQL=<br>".str_replace("$1", "'".$gmlid."'", $sql)."</p>";}
}
echo "\n<h2><img src='ico/Gericht.png' width='16' height='16' alt=''> Bau-, Raum- oder Bodenordnungsrecht</h2>";
if ($dbg > 0) {
	$zeianz=pg_num_rows($res);
	if ($zeianz > 1){
		echo "\n<p class='err'>Die Abfrage liefert mehr als ein (".$zeianz.") Bauordnungs-Objekt!</p>";
		if ($dbg > 2) {echo "\n<p class='dbg'>SQL=<br>".htmlentities(str_replace("$1","'".$gmlid."'",$sql), ENT_QUOTES, "UTF-8")."</p>";}
	}
}
if ($row = pg_fetch_assoc($res)) {
	echo "\n<table>"
		."\n<tr>"
			."\n\t<td class='li'>Art der Festlegung:</td>\n\t<td>"
			."<span class='wichtig'>".$row["adfbez"]."</span></td>"
		."\n</tr>";
		$enam=$row["name"];
		if ($enam != "") {
			echo "\n<tr>"
				."\n\t<td class='li'>Eigenname des Gebietes:</td>\n\t<td>".$enam."</td>"
			."\n</tr>";
		}
		echo "\n<tr>"
			."\n\t<td class='li'>Verfahrensnummer:</td>\n\t<td>".$row["rechtbez"]."</td>"
		."\n</tr>";
		$stell=$row["stelle"];
		if ($stell != "") {
			echo "\n<tr>"
				."\n\t<td class='li'>Dienststelle:</td>\n\t<td>".$row["stellbez"]."</td>"
			."\n</tr>";
		}
		echo "\n<tr>"
			."\n\t<td class='li'>Fl&auml;che:</td>";
			$flae=number_format($row["flae"],0,",",".")." m&#178;";
			echo "\n\t<td>".$flae."</td>"
		."\n</tr>"
	."\n</table>";
} else {
	echo "\n<p class='err'>Fehler! Kein Treffer bei gml_id=".$gmlid."</p>";
}

echo "\n<p class='nwlink'>"
	."\n\t<a href='javascript:imFenster(\"alkisbaurecht.php?gkz=".$gkz."&amp;gmlid=".$gmlid."\")' "
	."' title='Bau-, Raum- oder Bodenordnungsrecht'>Weitere Auskunft <img src='ico/Gericht.png' width='16' height='16' alt=''></a>"
."\n</p>";

?>

</body>
</html>

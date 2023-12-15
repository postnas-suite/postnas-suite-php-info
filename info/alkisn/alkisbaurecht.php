<?php
/*	alkisbaurecht.php - Baurecht

	ALKIS-Auskunft
	Author: Frank Jäger, Kommunales Rechenzentrum Minden-Ravensberg/Lippe (Lemgo)

	Version:
	2016-02-24 Version für norGIS-ALKIS-Import
	....
	2020-02-20 Authentifizierung ausgelagert in Function darf_ich()
	2020-12-03 function selbstverlinkung() statt $_SERVER['PHP_SELF']
	2020-12-15 Input-Validation und Strict Comparisation (===)
	2021-12-09 Neue Parameter: $katAmtMix (Kataster-Amt-Mix), $PrntBtn (Drucken-Schaltfläche)
	2022-01-13 Limit in Variable. Neue Functions LnkStf(), DsKy()
	2022-07-05 PHP 8.1: Connection verwenden bei "pg_prepare" und "pg_execute", keine NULL-Werte in String-Functions verwenden
*/
ini_set("session.cookie_httponly", 1);
session_start();
$showkey="n"; $nodebug=""; // Var. aus Parameter initalisieren
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
if (!preg_match('#^j{0,1}$#', $nodebug)) {die("Eingabefehler nodebug");}

include "alkis_conf_location.php";
include "alkisfkt.php";

echo <<<END
<!doctype html>
<html lang="de">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>ALKIS Bau-, Raum- oder Bodenordnungsrecht</title>
	<link rel="stylesheet" type="text/css" href="alkisauszug.css">
	<link rel="shortcut icon" type="image/x-icon" href="ico/Gericht.ico">
</head>
<body>
END;

$erlaubnis = darf_ich(); if ($erlaubnis === 0) {die('<p class="stop1">Abbruch</p></body>');}
$dbg=$debug;
if ($nodebug === "j") {$dbg=0;} 
$con = pg_connect($dbconn." options='--application_name=ALKIS-Auskunft_alkisbaurecht.php'");
if (!$con) echo "\n<p class='err'>Fehler beim Verbinden der DB</p>";

// Spalte "a.dokumentation" ist immer leer
$sql ="SELECT r.ogc_fid, r.artderfestlegung as adfkey, r.name, r.stelle, r.bezeichnung AS rechtbez, 
a.beschreibung AS adfbez, d.bezeichnung AS stellbez, d.stellenart, wd.beschreibung, wd.dokumentation, round(st_area(r.wkb_geometry)::numeric,0) AS flae 
FROM ax_bauraumoderbodenordnungsrecht r 
LEFT JOIN ax_artderfestlegung_bauraumoderbodenordnungsrecht a ON r.artderfestlegung = a.wert
LEFT JOIN ax_dienststelle d ON r.land=d.land AND r.stelle=d.stelle ".UnqKatAmt("r","d")
."LEFT JOIN ax_behoerde wd ON d.stellenart = wd.wert
WHERE r.gml_id= $1 AND r.endet IS NULL AND d.endet IS NULL;";

$v = array($gmlid);
$res = pg_prepare($con, "", $sql);
$res = pg_execute($con, "", $v);
if (!$res) {
	echo "\n<p class='err'>Fehler bei Baurecht.</p>";
	if ($dbg > 2) {echo "\n<p class='err'>SQL=<br>".htmlentities(str_replace("$1", "'".$gmlid."'", $sql), ENT_QUOTES, "UTF-8")."</p>";}
}
if ($dbg > 0) {
	$zeianz=pg_num_rows($res);
	if ($zeianz > 1){
		echo "\n<p class='err'>Die Abfrage liefert mehr als ein (".$zeianz.") Baurecht-Objekt!</p>";
		if ($dbg > 2) {echo "\n<p class='dbg'>SQL=<br>".htmlentities(str_replace("$1","'".$gmlid."'",$sql), ENT_QUOTES, "UTF-8")."</p>";}
	}
}
if ($row = pg_fetch_assoc($res)) {
	$artfest=$row["adfkey"];  // Art der Festlegung, Key
	$verfnr=$row["rechtbez"]; // Verfahrens-Nummer
	$enam=$row["name"];
	$stellb=$row["stellbez"];
	$stella=$row["stellenart"];
	$behb=$row["beschreibung"];
	$behd=$row["dokumentation"];

	echo "<p class='balken recht'>ALKIS Bau-, Raum- oder Bodenordnungsrecht ".$artfest."-".$verfnr."&nbsp;</p>";
	echo "\n<h2><img src='ico/Gericht.png' width='16' height='16' alt=''> Bau-, Raum- oder Bodenordnungsrecht</h2>";
	echo "\n<table>"
		."\n<tr>"
			."\n\t<td class='li'>Art der Festlegung:</td>"
			."\n\t<td>".DsKy($artfest, '* Art der Festlegung')."<span class='wichtig'>".$row["adfbez"]."</span></td>"
		."\n</tr>";
		if ($enam != "") {
			echo "\n<tr>"
				."\n\t<td class='li'>Eigenname des Gebietes:</td>"
				."\n\t<td>".$enam."</td>"
			. "\n</tr>";
		}
		if ($verfnr != "") {
			echo "\n<tr>"
				."\n\t<td class='li'>Verfahrensnummer:</td>"
				."\n\t<td>".$verfnr."</td>"
			."\n</tr>";
		}
		if ($stellb != "") { // z.B. Umlegung *mit* und Baulast *ohne* Dienststelle
			echo "\n<tr>"
			."\n\t<td class='li'>Dienststelle:</td>\n\t<td>".DsKy($row["stelle"], 'Dienststelle-*').$stellb."</td>"
			."\n</tr>";
			if ($stella != "") {
				echo "\n<tr>"
				."\n\t<td class='li'>Art der Dienststelle:</td>"
				."\n\t<td>".DsKy($stella, '* Art der Dienststelle')."<span title='".$behd."'>".$behb."</span>"."</td>"
				."\n</tr>";
			}
		}
		echo "\n<tr>"
			."\n\t<td class='li'>Fl&auml;che:</td>";
			$flae=number_format($row["flae"],0,",",".")." m&#178;";
			echo "\n\t<td>".$flae."</td>"
		."\n</tr>"
	. "\n</table>";
} else {
	echo "\n<p class='err'>Fehler! Kein Treffer bei gml_id=".$gmlid."</p>";
}

echo "\n<h2><img src='ico/Flurstueck.png' width='16' height='16' alt=''> betroffene Flurst&uuml;cke</h2>\n"
."\n<p>Ermittelt durch geometrische Verschneidung. Nach Gr&ouml;&szlig;e absteigend.</p>";

$fslimit=200;
$sql ="SELECT f.gml_id, g.bezeichnung, f.gemarkungsnummer, f.flurnummer, f.zaehler, f.nenner, f.amtlicheflaeche, round(st_area(ST_Intersection(r.wkb_geometry,f.wkb_geometry))::numeric,1) AS schnittflae 
FROM ax_flurstueck f  
JOIN ax_gemarkung g ON f.gemeindezugehoerigkeit_land=g.land AND f.gemarkungsnummer=g.gemarkungsnummer ".UnqKatAmt("f","g")
."JOIN ax_bauraumoderbodenordnungsrecht r
ON st_intersects(r.wkb_geometry,f.wkb_geometry) = true AND st_area(st_intersection(r.wkb_geometry,f.wkb_geometry)) > 0.05 
WHERE r.gml_id= $1 AND f.endet IS NULL AND r.endet IS NULL ";
if ($filtgem === '' ) {
	$v=array($gmlid);
} else {
	$sql.="AND f.gemeindezugehoerigkeit_kreis = $2 AND f.gemeindezugehoerigkeit_gemeinde = $3 "; // Zuständiges Gebiet
	$v=array($gmlid, $filtkreis, $filtgem);
}
$sql.="ORDER BY schnittflae DESC LIMIT ".$fslimit.";"; 
// > 0.0 ist gemeint, Ungenauigkeit durch st_simplify
// Limit: Flurbereinig. kann groß werden!
// Trotz Limit lange Antwortzeit, wegen OrderBy -> intersection

$res=pg_prepare($con, "", $sql);
$res=pg_execute($con, "", $v);
if (!$res) {
	echo "\n<p class='err'>Keine Flurst&uuml;cke ermittelt.<br></p>";
	//if ($dbg > 2) {echo "\n<p class='err'>SQL=<br>".htmlentities(str_replace("$1", "'".$gmlid."'", $sql), ENT_QUOTES, "UTF-8")."</p>";} // ggf. mehrere Parameter!
}

echo "\n<table class='fs'>"
	."\n<tr>"
		."\n\t<td class='head' title='Gemarkung'>Gemarkung</td>"
		."\n\t<td class='head' title='Flurnummer'>Flur</td>"
		."\n\t<td class='head' title='Flurst&uuml;cksnummer Z&auml;hler / Nenner'>Flurst&uuml;ck</td>"
		."\n\t<td class='heads fla' title='geometrische Schnittfl&auml;che'><img src='ico/sort.png' width='10' height='10' alt='' title='Sortierung'>Fl&auml;che</td>"
		."\n\t<td class='head fla' title='amtliche Flurst&uuml;cksfl&auml;che, Buchfl&auml;che'>von</td>"
		."\n\t<td class='head nwlink' title='Link zum Flurst&uuml;ck'>weitere Auskunft</td>"
	."\n</tr>";

	$fscnt=0;
	while($row = pg_fetch_assoc($res)) {
		$fscnt++;
		$gmkgnr=$row["gemarkungsnummer"];
		$gemarkung=$row["bezeichnung"];
		$nen=$row["nenner"];
		echo "\n<tr>"
			."\n\t<td>".DsKy($gmkgnr, 'Gemarkungsnummer').$gemarkung."</td>"
			."\n\t<td>".$row["flurnummer"]."</td>"
			."\n\t<td><span class='wichtig'>".$row["zaehler"];
			if ($nen != "") {echo "/".$nen;}
			echo "</span></td>"
			."\n\t<td class='fla'>".$row["schnittflae"]." m&#178;</td>"
			."\n\t<td class='fla'>".$row["amtlicheflaeche"]." m&#178;</td>"
			."\n\t<td class='nwlink noprint'>"
				."\n\t\t<a href='alkisfsnw.php?gkz=".$gkz."&amp;gmlid=".$row["gml_id"].LnkStf()."&amp;eig=n' "
					."title='Flurst&uuml;cksnachweis'>Flurst&uuml;ck "
					."\n\t\t\t<img src='ico/Flurstueck_Link.png' width='16' height='16' alt=''>"
				."\n\t\t</a>"
			."\n\t</td>"
		."\n</tr>";
	}
echo "\n</table>";

if ($fscnt === 0) { // nicht gefunden
	if ($filtgem === '' ) { // ungefiltert
		echo "\n<p class='err'>Kein Flurst&uuml;ck gefunden.</p>";
	} else { // Wahrscheinliche Ursache = Filter
		echo "\n<p class='err'>Kein Flurst&uuml;ck im berechtigten Bereich.</p>";
	//	if ($dbg > 2) {echo "\n<p class='err'>SQL=<br>".htmlentities($sql, ENT_QUOTES, "UTF-8")."<br>$1 = ".$gmlid." $2 = ".$filtkreis." $3 = ".$filtgem."</p>";}
	}
} elseif ($fscnt >= $fslimit) {
	echo "<p>... und weitere Flurst&uuml;cke (Limit ".$fslimit." erreicht).</p>";
}

pg_close($con);
echo "
<div class='buttonbereich noprint'>\n<hr>"
	."\n\t<a title='zur&uuml;ck' href='javascript:history.back()'><img src='ico/zurueck.png' width='16' height='16' alt='zur&uuml;ck' /></a>&nbsp;";
if ($PrntBtn==true){echo "\n\t<a title='Drucken' href='javascript:window.print()'><img src='ico/print.png' width='16' height='16' alt='Drucken'></a>&nbsp;";}
echo "\n</div>";

footer($gmlid, selbstverlinkung()."?", "");
?>

</body>
</html>

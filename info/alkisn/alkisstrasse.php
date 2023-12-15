<?php
/*	alkisstrasse.php

	ALKIS-Auskunft
	Author: Frank Jäger, Kommunales Rechenzentrum Minden-Ravensberg/Lippe (Lemgo)

	Alle Flurstücke an einer Straße anzeigen, egal ob "mit" oder "ohne" Hausnummer
	Parameter: "gml_id" aus der Tabelle "ax_lagebezeichnungkatalogeintrag"

	Version:
	2016-02-24 Version für norGIS-ALKIS-Import
	...
	2020-02-20 Authentifizierung ausgelagert in Function darf_ich()
	2020-12-15 Input-Validation und Strict Comparisation (===)
	2021-12-09 Neue Parameter: $katAmtMix (Kataster-Amt-Mix), $PrntBtn (Drucken-Schaltfläche)
	2022-01-13 Neue Functions LnkStf(), DsKy()
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
?>
<!doctype html>
<html lang="de">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>ALKIS Stra&szlig;e</title>
	<link rel="stylesheet" type="text/css" href="alkisauszug.css">
	<link rel="shortcut icon" type="image/x-icon" href="ico/Strassen.ico">
	<script type="text/javascript">
		function ALKISexport(phaus) {
			window.open(<?php echo "'alkisexport.php?gkz=".$gkz."&tabtyp=strasse&gmlid=".$gmlid."&haus='"; ?> + phaus);
		}
	</script>
</head>
<body>
<?php
$erlaubnis = darf_ich(); if ($erlaubnis === 0) { die('<p class="stop1">Abbruch</p></body>'); }
$dbg=$debug; // CONF in Arbeits-Variable
if ($nodebug === "j") {$dbg=0;} 

$con = pg_connect($dbconn." options='--application_name=ALKIS-Auskunft_alkisstrasse.php'");
if (!$con) echo "\n<p class='err'>Fehler beim Verbinden der DB</p>";

$sql ="SELECT s.land, s.regierungsbezirk, s.kreis, s.gemeinde, s.lage, s.bezeichnung AS snam, 
b.bezeichnung AS bnam, r.bezeichnung AS rnam, k.bezeichnung AS knam, g.bezeichnung AS gnam, o.gml_id AS ogml 
FROM ax_lagebezeichnungkatalogeintrag s 
JOIN ax_bundesland b ON s.land=b.land ".UnqKatAmt("s","b")
."JOIN ax_regierungsbezirk r ON s.land=r.land AND s.regierungsbezirk=r.regierungsbezirk ".UnqKatAmt("s","r")
."JOIN ax_kreisregion k ON s.land=k.land AND s.regierungsbezirk=k.regierungsbezirk AND s.kreis=k.kreis ".UnqKatAmt("s","k")
."JOIN ax_gemeinde g ON s.land=g.land AND s.regierungsbezirk=g.regierungsbezirk AND s.kreis=g.kreis AND s.gemeinde=g.gemeinde ".UnqKatAmt("s","g")
."LEFT JOIN ax_lagebezeichnungohnehausnummer o ON s.land=o.land AND s.regierungsbezirk=o.regierungsbezirk AND s.kreis=o.kreis AND s.gemeinde=o.gemeinde AND s.lage=o.lage 
WHERE s.gml_id= $1 AND s.endet IS NULL AND b.endet IS NULL AND r.endet IS NULL AND k.endet IS NULL AND g.endet IS NULL AND o.endet IS NULL ;"; 

$v=array($gmlid);
$res=pg_prepare($con, "", $sql);
$res=pg_execute($con, "", $v);
if (!$res) {
	echo "\n<p class='err'>Fehler bei Lagebezeichnungskatalogeintrag.</p>";
	if ($dbg > 2) {echo "\n<p class='err'>SQL=<br>".htmlentities(str_replace("$1", "'".$gmlid."'", $sql), ENT_QUOTES, "UTF-8")."</p>";}
}
if ($dbg > 0) {
	$zeianz=pg_num_rows($res);
	if ($zeianz > 1){
		echo "\n<p class='err'>Die Abfrage liefert mehr als ein (".$zeianz.") Stra&szlig;en-Objekt!</p>";
		if ($dbg > 2) {echo "\n<p class='dbg'>SQL=<br>".htmlentities(str_replace("$1","'".$gmlid."'",$sql), ENT_QUOTES, "UTF-8")."</p>";}
	}
}
if ($row = pg_fetch_assoc($res)) {
	$lage=$row["lage"]; // Str.schl.
	$snam=$row["snam"]; // Str.name
	$gem=$row["gemeinde"];
	$kennz=$gem."-".$lage." (".$snam.")"; // Schlüssel als Sucheingabe in NAV brauchbar?
	echo "\n<p class='balken strasse'>ALKIS Stra&szlig;e ".$kennz."&nbsp;</p>";
} else {
	echo "\n<p class='err'>Kein Treffer bei Lagebezeichnungskatalogeintrag.</p>";
}

echo "\n<h2>Stra&szlig;e</h2>";

// Tabelle Kennzeichen
echo "\n<table class='outer'>\n<tr>"
."\n\t<td class='ll'><img src='ico/Strassen.png' width='16' height='16' alt=''> Stra&szligenname:</td>"
."\n\t<td>"
	."\n\t\t<table class='kennzstra' title='Lage'>"
		."\n\t\t<tr>"
			."\n\t\t\t<td class='head'>Land</td>"
			."\n\t\t\t<td class='head'>Reg.-Bez.</td>"
			."\n\t\t\t<td class='head'>Kreis</td>"
			."\n\t\t\t<td class='head'>Gemeinde</td>"
			."\n\t\t\t<td class='head'>Stra&szlig;e</td>"
		."\n\t\t</tr>"
		."\n\t\t<tr>";
		echo "\n\t\t\t<td title='Bundesland'>".DsKy($row["land"], 'Bundesland-*').$row["bnam"]."&nbsp;</td>"
			."\n\t\t\t<td title='Regierungsbezirk'>".DsKy($row["regierungsbezirk"], 'Regierungsbezirk-*').$row["rnam"]."&nbsp;</td>"
			."\n\t\t\t<td title='Kreis'>".DsKy($row["kreis"], 'Kreis-*').$row["knam"]."&nbsp;</td>"
			."\n\t\t\t<td title='Gemeinde'>".DsKy($gem, 'Gemeinde-*').$row["gnam"]."&nbsp;</td>"
			."\n\t\t\t<td title='Stra&szlig;e'>".DsKy($lage, 'Stra&szlig;en-*')."<span class='wichtig'>".$snam."</span>&nbsp;</td>"
		."\n\t\t</tr>"
	."\n\t\t</table>";
echo "\n\t</td>\n\t<td>";

// Kopf Rechts:
$ogml=$row["ogml"]; // ID von "Lage Ohne HsNr"
if ($ogml != "") {
	echo "\n\t\t<p class='nwlink noprint'>"
		."\n\t\t<a href='alkislage.php?gkz=".$gkz."&amp;ltyp=o&amp;gmlid=".$ogml.LnkStf()
		."' title='Lage Ohne Hausnummer'>Lage <img src='ico/Lage_an_Strasse.png' width='16' height='16' alt=''></a>"
	."\n\t\t</p>";
}
echo "\n\t</td>\n</tr>\n</table>";
pg_free_result($res);

// F L U R S T Ü C K E
echo "\n\n<h3 id='fs'><img src='ico/Flurstueck.png' width='16' height='16' alt=''> Flurst&uuml;cke</h3>"
."\n<p>Zusammenfassung von 'Lage mit Hausnummer' und 'Lage ohne Hausnummer' an dieser Straße</p>";

// ax_Flurstueck >weistAuf> ax_LagebezeichnungMitHausnummer  > = h = Hauptgebaeude 
// ax_Flurstueck >zeigtAuf> ax_LagebezeichnungOhneHausnummer > = s = Strasse
// Suchkriterium: gml_id aus Katalog
$sql ="SELECT fh.gemarkungsnummer, gh.bezeichnung, fh.gml_id, fh.flurnummer, fh.zaehler, fh.nenner, fh.amtlicheflaeche, lh.gml_id AS lgml, lh.hausnummer, 'm' AS ltyp
 FROM ax_flurstueck fh 
 JOIN ax_lagebezeichnungmithausnummer lh ON lh.gml_id=ANY(fh.weistAuf) 
 JOIN ax_gemarkung gh ON fh.land=gh.land AND fh.gemarkungsnummer=gh.gemarkungsnummer ".UnqKatAmt("fh","gh")
."JOIN ax_lagebezeichnungkatalogeintrag sh ON lh.land=sh.land AND lh.regierungsbezirk=sh.regierungsbezirk AND lh.kreis=sh.kreis AND lh.gemeinde=sh.gemeinde AND lh.lage=sh.lage 
 WHERE sh.gml_id = $1 AND fh.endet IS NULL AND lh.endet IS NULL AND gh.endet IS NULL AND sh.endet IS NULL
UNION SELECT fs.gemarkungsnummer, gs.bezeichnung, fs.gml_id, fs.flurnummer, fs.zaehler, fs.nenner, fs.amtlicheflaeche, ls.gml_id AS lgml, '' AS hausnummer, 'o' AS ltyp
 FROM ax_flurstueck fs 
 JOIN ax_lagebezeichnungohnehausnummer ls ON ls.gml_id=ANY(fs.zeigtauf) 
 JOIN ax_gemarkung gs ON fs.land=gs.land AND fs.gemarkungsnummer=gs.gemarkungsnummer ".UnqKatAmt("fs","gs")
."JOIN ax_lagebezeichnungkatalogeintrag ss ON ls.land=ss.land AND ls.regierungsbezirk=ss.regierungsbezirk AND ls.kreis=ss.kreis AND ls.gemeinde=ss.gemeinde AND ls.lage=ss.lage 
 WHERE ss.gml_id = $1 AND fs.endet IS NULL AND ls.endet IS NULL AND gs.endet IS NULL AND ss.endet IS NULL
ORDER BY gemarkungsnummer, flurnummer, zaehler, nenner;";

$v=array($gmlid);
$resf=pg_prepare($con, "", $sql);
$resf=pg_execute($con, "", $v);
if (!$resf) {
	echo "\n<p class='err'>Fehler bei Flurst&uuml;ck.</p>";
	if ($dbg > 2) {echo "\n<p class='err'>SQL=<br>".htmlentities(str_replace("$1", "'".$gmlid."'", $sql), ENT_QUOTES, "UTF-8")."</p>";}	
}

echo "\n<table class='fs'>"
."\n<tr>"
	."\n\t<td class='heads' title='Name der Gemarkung (Ortsteil)'>Gemarkung<img src='ico/sort.png' width='10' height='10' alt='' title='Sortierung'></td>"
	."\n\t<td class='heads' title='Flur-Nummer'>Flur<img src='ico/sort.png' width='10' height='10' alt='' title='Sortierung'></td>"
	."\n\t<td class='heads' title='Flurst&uuml;cksnummer (Z&auml;hler / Nenner)'><img src='ico/sort.png' width='10' height='10' alt='' title='Sortierung'>Flurst.</td>"
	."\n\t<td class='head fla' title='amtliche Fl&auml;che in Quadratmeter'>Fl&auml;che</td>"
	."\n\t<td class='head hsnr' title='Hausnummer aus der Lagebezeichnung des Flurst&uuml;cks'>HsNr.</td>"
	."\n\t<td class='head nwlink noprint' title='Verlinkung zu anderen Nachweis-Arten und verbundenen Objekten'>weitere Auskunft</td>"
."\n</tr>";
$j=0;
$gwgmkg=""; // Gruppenwechsel
$gwflur="";
$cnths=0; // Count Haus-Lagebez.

$zpaar=false; // Zeilen-Farbwechsel
while($rowf = pg_fetch_assoc($resf)) {
	$gmkg=$rowf["bezeichnung"];
	$flur=str_pad($rowf["flurnummer"], 3, "0", STR_PAD_LEFT);
	$fskenn=$rowf["zaehler"]; // Bruchnummer
	if ($rowf["nenner"] != "") {$fskenn.="/".$rowf["nenner"];}
	$flae=number_format($rowf["amtlicheflaeche"],0,",",".") . " m&#178;";
	$lgml=$rowf["lgml"]; // ID von "Lage Mit/Ohne"
	$ltyp=$rowf["ltyp"]; // mit/ohne HsNr

	if ($zpaar) {$trclass='paa';} else {$trclass='unp';}
	$zpaar=!$zpaar;
	echo "\n<tr class='".$trclass."'>"
		."\n\t<td>".DsKy($rowf["gemarkungsnummer"], 'Gemarkungsnummer');
		if ($gwgmkg != $gmkg) {
			echo "<b>".$gmkg."</b></td>";
			$gwgmkg=$gmkg;
			$gwflur="";
		} else {
			echo $gmkg."</td>";
		}

		if ($gwflur != $flur) {
			echo "\n\t<td><b>".$flur."</b></td>";
			$gwflur=$flur;
		} else {
			echo "\n\t<td>".$flur."</td>";
		}

		echo "\n\t<td><span class='wichtig'>".$fskenn."</span></td>"
		."\n\t<td class='fla'>".$flae."</td>"
		."\n\t<td class='hsnr'>".$rowf["hausnummer"]."</td>"
		."\n\t<td>\n\t\t<p class='nwlink noprint'>";
			if ($ltyp === 'm') { // nur Typ "Mit Haus" anzeigen. Dar Typ 'o' ist immer gleich und identisch mit dem Link im Kopf
				echo "\n\t\t<a href='alkislage.php?gkz=".$gkz."&amp;ltyp=".$ltyp."&amp;gmlid=".$lgml.LnkStf()
				."' title='Lagebezeichnung mit Hausnummer'>Lage <img src='ico/Lage_mit_Haus.png' width='16' height='16' alt=''></a>&nbsp;";
				$cnths++;
			}
			// Link Flurstücksnachweis
			echo "\n\t\t<a href='alkisfsnw.php?gkz=".$gkz."&amp;gmlid=".$rowf["gml_id"].LnkStf()."&amp;eig=n"
			."' title='Flurst&uuml;cksnachweis'>Flurst&uuml;ck <img src='ico/Flurstueck_Link.png' width='16' height='16' alt=''></a>"
		."\n\t\t</p>\n\t</td>"
	."\n</tr>";
	$j++;
}
echo "\n</table>";
if ($j > 6) {
	echo "<p class='cnt'>".$j." Flurst&uuml;cke";
	if ($cnths > 1) {echo " und ".$cnths." Hauptgeb&auml;ude";}
	echo " mit dieser Stra&szlig;e in der Lagebezeichnung</p>";
}
pg_free_result($resf);

echo "\n<div class='buttonbereich noprint'>\n<hr>"
	."\n\t<a title='zur&uuml;ck' href='javascript:history.back()'><img src='ico/zurueck.png' width='16' height='16' alt='zur&uuml;ck'></a>&nbsp;";
if ($PrntBtn==true){echo "\n\t<a title='Drucken' href='javascript:window.print()'><img src='ico/print.png' width='16' height='16' alt='Drucken'></a>&nbsp;";}
echo "\n\t<a title='Export als CSV' href=\"javascript:ALKISexport('')\">alle<img src='ico/download.png' width='16' height='16' alt='Export'></a>&nbsp;"
	."\n\t<a title='CSV - nur Flurst&uuml;cke mit Hausnummer' href=\"javascript:ALKISexport('m')\">mit HsNr<img src='ico/download.png' width='16' height='16' alt='Export'></a>&nbsp;"
	."\n\t<a title='CSV - nur Flurst&uuml;cke ohne Hausnummer' href=\"javascript:ALKISexport('o')\">ohne<img src='ico/download.png' width='16' height='16' alt='Export'></a>&nbsp;"
."\n</div>";

footer($gmlid, selbstverlinkung()."?", ""); 
?>

</body>
</html>

<?php
/*	alkisgebaeudenw.php

	ALKIS-Buchauskunft
	Author: Frank Jäger, Kommunales Rechenzentrum Minden-Ravensberg/Lippe (Lemgo)

	Gebäude- und Bauwerks-Nachweis für ein Flurstück

	Version:
	2016-02-24 Version für norGIS-ALKIS-Import
	...
	2020-02-20 Authentifizierung ausgelegert in Function darf_ich()
	2020-12-03 function selbstverlinkung() statt $_SERVER['PHP_SELF']
	2020-12-15 Verschneidung mit "sonstige Bauwerke", Input-Validation und Strict Comparisation
	2021-03-09 Verschneidung mit weiteren Bauwerks-Tabellen.
	2021-03-11 Adresse(n) des gehörtZu-Haus zum Bauwerk nicht mehr hier anzeigen sondern im neuen Bauwerk-Modul.
	2021-12-09 Neue Parameter: $katAmtMix (Kataster-Amt-Mix), $PrntBtn (Drucken-Schaltfläche)
	2022-01-13 Neue Functions LnkStf(), DsKy()
	2022-02-17 Neue Bauwerks-Typen
	2022-07-05 PHP 8.1: Connection verwenden bei "pg_prepare" und "pg_execute", keine NULL-Werte in String-Functions verwenden
	2022-11-02 Fehlerkorrektur $gzus
*/

// Kopf für die Anzeige der Gebäude. Nur wenn tatsächlich Gebäude vorkommen.
function geb_tab_head() {
	// Überschrift
	echo "\n\n<h3><img src='ico/Haus.png' width='16' height='16' alt=''> Geb&auml;ude</h3>"
	."\n<p>.. auf oder an dem Flurst&uuml;ck. Ermittelt durch Verschneidung der Geometrie.</p>";

	// Tabellen-Kopf
	echo "\n<hr>\n<table class='geb'>";
	echo "\n<tr>"
		."\n\t<td class='head' title='ggf. Geb&auml;udename'>Name</td>"
		."\n\t<td class='heads fla' title='Schnittfl&auml;che zwischen Flurst&uuml;ck und Geb&auml;ude'><img src='ico/sortd.png' width='10' height='10' alt='' title='Sortierung (absteigend)'>Fl&auml;che</td>"
		."\n\t<td class='head' title='gesamte Geb&auml;udefl&auml;che, liegt teilweise auf Nachbar-Flurst&uuml;ck'>&nbsp;</td>"
		."\n\t<td class='head' title='Geb&auml;udefunktion ist die zum Zeitpunkt der Erhebung vorherrschend funktionale Bedeutung des Geb&auml;udes'>Funktion</td>"
		."\n\t<td class='head' title='Bauweise ist die Beschreibung der Art der Bauweise'>Bauweise</td>"
		."\n\t<td class='head' title='Zustand beschreibt die Beschaffenheit oder die Betriebsbereitschaft von Geb&auml;ude. Diese Attributart wird nur dann optional gef&uuml;hrt, wenn der Zustand des Geb&auml;udes vom nutzungsf&auml;higen Zustand abweicht.'>Zustand</td>"
		."\n\t<td class='head nwlink' title='Lagebezeichnung mit Stra&szlig;e und Hausnummer'>Lage</td>"
		."\n\t<td class='head nwlink' title='Link zu den kompletten Hausdaten'>Haus</td>"
	."\n</tr>";
}
	
function bauw_tab_head() {
// Kopf für die Anzeige der Bauwerke. Nur wenn tatsächlich Bauwerke vorkommen.	

	// Überschrift
	echo "\n\n<h3><img src='ico/Haus.png' width='16' height='16' alt=''> Bauwerke</h3>"
	."\n<p>.. auf oder an dem Flurst&uuml;ck. Ermittelt durch Verschneidung der Geometrie.</p>";

	// Tabellen-Kopf
	echo "\n<hr>\n<table class='geb'>";
	echo "<tr><td colspan=3></td><td colspan=3 class='heads gw'><img src='ico/sort.png' width='10' height='10' alt='' title='Sortierung'>Bauwerks-Typ</td></tr>";
	echo "\n<tr>";
	echo "\n\t<td class='head' title='Bezeichnung und/oder Bauwerksname'>Name</td>"
		."\n\t<td class='heads fla' title='Schnittfl&auml;che zwischen Flurst&uuml;ck und Bauwerk'><img src='ico/sortd.png' width='10' height='10' alt='' title='Sortierung (absteigend)'>Fl&auml;che</td>"
		."\n\t<td class='head' title='gesamte Bauwerksfl&auml;che, liegt teilweise auf Nachbar-Flurst&uuml;ck'>&nbsp;</td>"
		."\n\t<td class='head' title='Bauwerksfunktion'>Funktion</td>"
		."\n\t<td class='head nwlink' title='Daten des zugehörigen Geb&auml;udes'>zum Haus</td>"
		."\n\t<td class='head nwlink' title='Detaillierte Daten zu diesem Bauwerk'>Bauwerk</td>"
	."\n</tr>";
}

// S T A R T
ini_set("session.cookie_httponly", 1);
session_start();
$showkey="n"; $nodebug="";	// Var. aus Parameter initalisieren
$cntget = extract($_GET);	// Parameter in Variable umwandeln

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
	<title>ALKIS Geb&auml;udenachweis</title>
	<link rel="stylesheet" type="text/css" href="alkisauszug.css">
	<link rel="shortcut icon" type="image/x-icon" href="ico/Haus.ico">
</head>
<body>
END;

$erlaubnis = darf_ich(); if ($erlaubnis === 0) { die('<p class="stop1">Abbruch</p></body>'); }
$dbg=$debug;
if ($nodebug === "j") {$dbg=0;} 
$con = pg_connect($dbconn." options='--application_name=ALKIS-Auskunft_alkisgebaeudenw.php'");
if (!$con) echo "\n<p class='err'>Fehler beim Verbinden der DB</p>";

// Flurstück
$sqlf ="SELECT f.flurnummer, f.zaehler, f.nenner, f.amtlicheflaeche, g.gemarkungsnummer, g.bezeichnung 
FROM ax_flurstueck f LEFT JOIN ax_gemarkung g ON f.land=g.land AND f.gemarkungsnummer=g.gemarkungsnummer ".UnqKatAmt("f","g")
."WHERE f.gml_id= $1 AND f.endet IS NULL AND g.endet IS NULL;";
$v=array($gmlid);
$resf=pg_prepare($con, "", $sqlf);
$resf=pg_execute($con, "", $v);
if (!$resf) {
	echo "\n<p class='err'>Fehler bei Flurst&uuml;cksdaten.</p>";
	if ($dbg > 2) {echo "\n<p class='err'>SQL=<br>".str_replace("$1","'".$gmlid."'",$sqlf)."'</p>";}
	if ($dbg > 1) {echo "<p class='dbg'>Fehler:".pg_result_error($resf)."</p>";}
}

if ($dbg > 0) {
	$zeianz=pg_num_rows($resf);
	if ($zeianz > 1){
		echo "\n<p class='err'>Die Abfrage liefert mehr als ein Flurst&uuml;cks-Objekt!</p>";
		if ($dbg > 2) {echo "\n<p class='dbg'>SQL=<br>".htmlentities(str_replace("$1","'".$gmlid."'",$sqlf), ENT_QUOTES, "UTF-8")."</p>";}
	}
}
if ($rowf = pg_fetch_assoc($resf)) {
	$gemkname=htmlentities($rowf["bezeichnung"], ENT_QUOTES, "UTF-8");
	$gmkgnr=$rowf["gemarkungsnummer"];
	$flurnummer=$rowf["flurnummer"];
	$flstnummer=$rowf["zaehler"];
	$nenner=$rowf["nenner"];
	if ($nenner > 0) { // BruchNr
		$flstnummer.="/".$nenner;
	} 
	$flstflaeche = $rowf["amtlicheflaeche"] ;
} else {
	echo "\n<p class='err'>Fehler! Kein Treffer fuer gml_id=".$gmlid."</p>";
}

echo "<p class='balken geb'>ALKIS Flurst&uuml;ck (Geb&auml;ude und Bauwerke) ".$gmkgnr."-".$flurnummer."-".$flstnummer."&nbsp;</p>"; // Balken
echo "\n<h2>Flurst&uuml;ck (Geb&auml;ude und Bauwerke)</h2>";
echo "\n<table class='outer'>" // Kopf
	."\n<tr>"
		."\n\t<td>"
		."\n\t<td class='ll'><img src='ico/Flurstueck.png' width='16' height='16' alt=''> Kennzeichen:</td>"
		."\n\t<td>"
			."\n\t\t<table class='kennzfs' title='Flurst&uuml;ckskennzeichen'>" // Kennzeichen in Rahmen
				."\n\t\t<tr>"
					."\n\t\t\t<td class='head'>Gemarkung</td>"
					."\n\t\t\t<td class='head'>Flur</td>"
					."\n\t\t\t<td class='head'>Flurst-Nr.</td>"
				."\n\t\t</tr>\n\t\t<tr>"
					."\n\t\t\t<td title='Gemarkung'>".DsKy($gmkgnr, 'Gemarkungsnummer').$gemkname."&nbsp;</td>"
					."\n\t\t\t<td title='Flurnummer'>".$flurnummer."</td>"
					."\n\t\t\t<td title='Flurst&uuml;cksnummer (Z&auml;hler / Nenner)'><span class='wichtig'>".$flstnummer."</span></td>"
				."\n\t\t</tr>"
			."\n\t\t</table>"
		."\n\t</td>"
		."\n\t<td>"
			."\n\t\t<p class='nwlink noprint'>" // Links zu anderem Nachweis
				."\n\t\t\t<a href='alkisfsnw.php?gkz=".$gkz."&amp;gmlid=".$gmlid.LnkStf()
				."&amp;eig=n' title='Flurst&uuml;cksnachweis'>Flurst&uuml;ck <img src='ico/Flurstueck_Link.png' width='16' height='16' alt=''></a>"
			."\n\t\t</p>"
		."\n\t</td>"
	."\n</tr>"
."\n</table>";

echo "\n<p class='fsd'>Flurst&uuml;cksfl&auml;che: <b>".number_format($flstflaeche,0,",",".") . " m&#178;</b></p>";
pg_free_result($resf);

// Gebäude
$sqlg ="SELECT g.gml_id, g.name, g.bauweise, g.gebaeudefunktion, coalesce(h.beschreibung, '') AS bauweise_beschreibung, u.beschreibung AS bezeichner, u.dokumentation AS gfktd, g.zustand, coalesce(z.beschreibung, '') AS bzustand, 
round(st_area(g.wkb_geometry)::numeric,2) AS gebflae, "; // GEB-Fläche, auch ausserhalb des FS
$sqlg.="round(st_area(ST_Intersection(g.wkb_geometry,f.wkb_geometry))::numeric,2) AS schnittflae, "; // wie viel vom GEB liegt im FS?
$sqlg.="st_within(g.wkb_geometry,f.wkb_geometry) as drin 
 FROM ax_flurstueck f, ax_gebaeude g 
 LEFT JOIN ax_bauweise_gebaeude h ON g.bauweise = h.wert
 LEFT JOIN ax_gebaeudefunktion u ON g.gebaeudefunktion = u.wert
 LEFT JOIN ax_zustand_gebaeude z ON g.zustand = z.wert 
WHERE f.gml_id= $1 AND f.endet IS NULL and g.endet IS NULL ";

// "within" -> nur Geb., die komplett im FS liegen. "intersects" -> auch teil-überlappende Flst.
$sqlg.="AND st_intersects(g.wkb_geometry,f.wkb_geometry) = true ";
// RLP: keine Relationen zu Nebengebäuden. Auf Qualifizierung verzichten, sonst werden Nebengebäude nicht angezeigt
//$sqlg.="AND (v.beziehungsart='zeigtAuf' OR v.beziehungsart='hat') ";
$sqlg.="ORDER BY schnittflae DESC;";

$v=array($gmlid);
$resg=pg_prepare($con, "", $sqlg);
$resg=pg_execute($con, "", $v);
if (!$resg) {
	echo "\n<p class='err'>Fehler bei Geb&auml;ude-Verschneidung.</p>";
	if ($dbg > 2) {echo "\n<p class='err'>SQL=<br>".str_replace("$1","'".$gmlid."'",$sqlg)."'</p>";}
	if ($dbg > 1) {echo "<p class='dbg'>Fehler:".pg_result_error($resg)."</p>";}
}
$gebnr=0;
$gebflsum=0;
while($rowg = pg_fetch_assoc($resg)) {
	$gebnr++;
	if ($gebnr === 1) {geb_tab_head();} // Tab.-Kopf
	$ggml=$rowg["gml_id"];
	$gebflsum=$gebflsum + $rowg["schnittflae"];
	if (is_null($rowg["name"])) {
		$gnam="";
	} else {
		$gnam=trim(trim($rowg["name"], "{}"), '"'); // Gebäude-Name ist ein Array in der DB: '{"A","B"}'
	}
	// Mehrfachbelegung nur theoretisch. Entklammern reicht. Mal mit und mal ohne "" drum!?
	$gfktk=htmlentities($rowg["gebaeudefunktion"], ENT_QUOTES, "UTF-8");  // Geb.-Funktion Key
	$gfktv=htmlentities($rowg["bezeichner"], ENT_QUOTES, "UTF-8"); // -Value
	$gfktd=htmlentities($rowg["gfktd"], ENT_QUOTES, "UTF-8"); // -Description

	$gbauw=$rowg["bauweise"];
	$gbauwb=htmlentities($rowg["bauweise_beschreibung"], ENT_QUOTES, "UTF-8");

	$gzus=$rowg["zustand"];
	$gzustand=htmlentities($rowg["bzustand"], ENT_QUOTES, "UTF-8");

	// 3 Fälle unterscheiden:
	if ($rowg["drin"] === "t") { // Gebäude liegt komplett in Flurstück
		$f1=$rowg["schnittflae"]." m&#178;";
		$f2="&nbsp;";
		$gstyle="gin"; // siehe .css
	} else {
		if ($rowg["schnittflae"] === "0.00") { // Gebäude angrenzend (Grenzbebauung)
			$gstyle="gan";
			$f1="&nbsp;";
			$f2="angrenzend";
		} else { // Teile des Gebäudes stehen auf dem Flurstück
			$gstyle="gtl";
			$f1=$rowg["schnittflae"]." m&#178;";
			$f2="(von ".$rowg["gebflae"]." m&#178;)";
		}
	}

	echo "\n<tr>"
		."\n\t<td>";
		if ($gnam != "") {echo "<span title='Geb&auml;udename'>".$gnam."</span><br>";}
		echo "</td>"
		."\n\t<td class='fla'>".$f1."</td>\n\t<td class='".$gstyle."'>".$f2."</td>" // Flächenangaben
		."\n\t<td title='".$gfktd."'>".DsKy($gfktk, 'Funktion-*').$gfktv."</td>"
		."\n\t<td>";
		if ($gbauw != "") {
			echo DsKy($gbauw, 'Bauweise-*').$gbauwb;
		}
		echo "</td>\n\t<td>";
		if ($gzus != "") {
			echo DsKy($gzus, 'Zustand-*').$gzustand;
		}
		echo "</td>";

		// 2 Spalten mit Links zu anderen Nachweisen: 1. Lage, 2. Gebäude
		echo "\n\t<td class='nwlink noprint'>"; // Link Lage

		// Zu EINEM Gebäude mehrere Lagebezeichnungen mit Haus- oder Pseudo-Nummer möglich, alle in ein TD 
		// HAUPTgebäude  Geb >zeigtAuf> lage (mehrere)
		$sqll="SELECT 'm' AS ltyp, l.gml_id AS lgml, s.lage, s.bezeichnung, l.hausnummer, '' AS laufendenummer "
		."FROM ax_gebaeude g JOIN ax_lagebezeichnungmithausnummer l ON l.gml_id=ANY(g.zeigtauf) "
		."JOIN ax_lagebezeichnungkatalogeintrag s ON l.kreis=s.kreis AND l.gemeinde=s.gemeinde AND l.lage=s.lage "
		."WHERE g.gml_id= $1 AND g.endet IS NULL AND l.endet IS NULL AND s.endet IS NULL ";

		// UNION - oder NEBENgebäude  Geb >hat> Pseudo
		$sqll.="UNION SELECT 'p' AS ltyp, l.gml_id AS lgml, s.lage, s.bezeichnung, l.pseudonummer AS hausnummer, l.laufendenummer "
		."FROM ax_gebaeude g JOIN ax_lagebezeichnungmitpseudonummer l ON l.gml_id=g.hat "
		."JOIN ax_lagebezeichnungkatalogeintrag s ON l.kreis=s.kreis AND l.gemeinde=s.gemeinde AND l.lage=s.lage "
		."WHERE g.gml_id= $1 AND g.endet IS NULL AND l.endet IS NULL AND s.endet IS NULL "; // ID des Hauses"
	
		$sqll.="ORDER BY bezeichnung, hausnummer, laufendenummer;";
		$v = array($ggml);
		$resl = pg_prepare($con, "", $sqll);
		$resl = pg_execute($con, "", $v);
		if (!$resl) {
			echo "\n<p class='err'>Fehler bei Lage mit HsNr.</p>";
			if ($dbg > 2) {echo "\n<p class='dbg'>SQL=<br>".str_replace("$1","'".$gmlid."'",$sqll)."'</p>";}
			if ($dbg > 1) {echo "<p class='dbg'>Fehler:".pg_result_error($resl)."</p>";}
		}
		while($rowl = pg_fetch_assoc($resl)) { // LOOP: Lagezeilen
			$ltyp=$rowl["ltyp"]; // Lagezeilen-Typ
			$skey=$rowl["lage"]; // Str.-Schluessel
			$snam=htmlentities($rowl["bezeichnung"], ENT_QUOTES, "UTF-8"); //-Name
			$hsnr=$rowl["hausnummer"];
			$hlfd=$rowl["laufendenummer"];
			$gmllag=$rowl["lgml"];
			if ($ltyp === "p") {
				$lagetitl="Nebengeb&auml;ude - Pseudonummer";
				$lagetxt="Nebengeb. ".$hlfd; // + HausNr??
			} else {
				$lagetitl="Hauptgebäude - Hausnummer";
				$lagetxt=$snam." ".$hsnr;
			}
			echo "\n\t\t<a title='".$lagetitl."' href='alkislage.php?gkz=".$gkz."&amp;gmlid=".$gmllag."&amp;ltyp=".$ltyp.LnkStf()."'>"
			.DsKy($skey, 'Stra&szlig;en-*').$lagetxt."&nbsp;<img src='ico/Lage_mit_Haus.png' width='16' height='16' alt=''></a><br>";
		} // Ende Loop Lage m.H.
		pg_free_result($resl);
		echo "\n\t</td>";

		echo "\n\t<td class='nwlink noprint'>" // Link Haus
			."\n\t\t<a title='Daten zum Geb&auml;ude-Objekt' href='alkishaus.php?gkz=".$gkz."&amp;gmlid=".$ggml.LnkStf()
			."'>Haus&nbsp;<img src='ico/Haus.png' width='16' height='16' alt=''></a>"
		."\n\t</td>"
	."\n</tr>";
}
// Footer Gebäude
if ($gebnr === 0) {
	echo "<p><br>Kein Geb&auml;ude auf diesem Flurst&uuml;ck.<br>&nbsp;</p>";
} else {
	echo "\n<tr>"
		."\n\t<td>Summe:</td>"
		."\n\t<td class='fla sum' title='von Geb&auml;uden &uuml;berbaute Fl&auml;che des Flurst&uuml;cks'>".number_format($gebflsum,0,",",".")."&nbsp;&nbsp;&nbsp;&nbsp;m&#178;</td>"
		."\n\t<td colspan='6'>&nbsp;</td>"
	."\n</tr>"
	."\n</table>";
	$unbebaut = number_format(($flstflaeche - $gebflsum),0,",",".") . " m&#178;";
	echo "\n<p>\n<br>Flurst&uuml;cksfl&auml;che abz&uuml;glich Geb&auml;udefl&auml;che: <b>".$unbebaut."</b></p>\n<br>";
}
pg_free_result($resg);

// B a u w e r k e
// Konstanten für Sortierung und Gruppierung
$btyp_verkehr=1; $btyp_gewaesser=2; $btyp_sonst=3; $btyp_indu=4; $btyp_sport=5;
$btyp_leitg=6; $btyp_trans=7; $btyp_turm=8; $btyp_vorrat=9;
$btyp_hist=10; $btyp_heil=11; $btyp_oeff=12; $btyp_bpkt=13;

// Tabllen-Alias, 2-3 stellig. 1. Stelle: f_=Flurstück, b_=Bauwerk, k_=Key = Schlüsseltabelle. // 2.-3. Stelle: wie Konstante

// 1 - V e r k e h r
$sqlb="SELECT ".$btyp_verkehr." AS bwtyp, b1.gml_id, 
 b1.bauwerksfunktion, k1.beschreibung, k1.dokumentation, b1.bezeichnung, b1.name, NULL AS gehoertzu, 
 round(st_area(b1.wkb_geometry)::numeric,2) AS gebflae, round(st_area(ST_Intersection(b1.wkb_geometry,f1.wkb_geometry))::numeric,2) AS schnittflae, 
 st_within(b1.wkb_geometry,f1.wkb_geometry) as drin, GeometryType(b1.wkb_geometry) as bgeotyp 
 FROM ax_flurstueck f1 
 JOIN ax_bauwerkimverkehrsbereich b1 ON st_intersects(b1.wkb_geometry,f1.wkb_geometry) = true
 LEFT JOIN ax_bauwerksfunktion_bauwerkimverkehrsbereich k1 ON b1.bauwerksfunktion = k1.wert 
 WHERE f1.gml_id = $1 AND f1.endet IS NULL AND b1.endet IS NULL ";
// 2 - G e w ä s s e r
$sqlb.="UNION
 SELECT ".$btyp_gewaesser." AS bwtyp, b2.gml_id, b2.bauwerksfunktion, k2.beschreibung, k2.dokumentation, b2.bezeichnung, b2.name, NULL AS gehoertzu, 
 round(st_area(b2.wkb_geometry)::numeric,2) AS gebflae, round(st_area(ST_Intersection(b2.wkb_geometry,f2.wkb_geometry))::numeric,2) AS schnittflae, 
 st_within(b2.wkb_geometry,f2.wkb_geometry) as drin, GeometryType(b2.wkb_geometry) as bgeotyp 
 FROM ax_flurstueck f2 
 JOIN ax_bauwerkimgewaesserbereich b2 ON st_intersects(b2.wkb_geometry,f2.wkb_geometry) = true
 LEFT JOIN ax_bauwerksfunktion_bauwerkimgewaesserbereich k2 ON b2.bauwerksfunktion = k2.wert 
 WHERE f2.gml_id = $1 AND f2.endet IS NULL AND b2.endet IS NULL ";
// 3 - S o n s t i g e  Bauwerke
$sqlb.="UNION
 SELECT ".$btyp_sonst." AS bwtyp, b3.gml_id, b3.bauwerksfunktion, k3.beschreibung, k3.dokumentation, b3.bezeichnung, b3.name, b3.gehoertzu, 
 round(st_area(b3.wkb_geometry)::numeric,2) AS gebflae, round(st_area(ST_Intersection(b3.wkb_geometry,f3.wkb_geometry))::numeric,2) AS schnittflae, 
 st_within(b3.wkb_geometry,f3.wkb_geometry) as drin, GeometryType(b3.wkb_geometry) as bgeotyp 
 FROM ax_flurstueck f3 
 JOIN ax_sonstigesbauwerkodersonstigeeinrichtung b3 ON st_intersects(b3.wkb_geometry,f3.wkb_geometry) = true
 LEFT JOIN ax_bauwerksfunktion_sonstigesbauwerkodersonstigeeinrichtun k3 ON b3.bauwerksfunktion = k3.wert 
 WHERE f3.gml_id = $1 AND f3.endet IS NULL AND b3.endet IS NULL ";
// 4 - Bauwerk oder Anlage für  I n d u s t r i e  und Gewerbe
$sqlb.="UNION
 SELECT ".$btyp_indu." AS bwtyp, b4.gml_id, b4.bauwerksfunktion, k4.beschreibung, k4.dokumentation, b4.bezeichnung, b4.name, NULL AS gehoertzu, 
 round(st_area(b4.wkb_geometry)::numeric,2) AS gebflae, round(st_area(ST_Intersection(b4.wkb_geometry,f4.wkb_geometry))::numeric,2) AS schnittflae, 
 st_within(b4.wkb_geometry,f4.wkb_geometry) as drin, GeometryType(b4.wkb_geometry) as bgeotyp 
 FROM ax_flurstueck f4 
 JOIN ax_bauwerkoderanlagefuerindustrieundgewerbe b4 ON st_intersects(b4.wkb_geometry,f4.wkb_geometry) = true
 LEFT JOIN ax_bauwerksfunktion_bauwerkoderanlagefuerindustrieundgewer k4 ON b4.bauwerksfunktion = k4.wert 
 WHERE f4.gml_id = $1 AND f4.endet IS NULL AND b4.endet IS NULL ";
// 5 - Bauwerk oder Anlage für  S p o r t , Freizeit und Erholung
$sqlb.="UNION
 SELECT ".$btyp_sport." AS bwtyp, b5.gml_id, b5.bauwerksfunktion, k5.beschreibung, k5.dokumentation, NULL AS bezeichnung, b5.name, NULL AS gehoertzu, 
 round(st_area(b5.wkb_geometry)::numeric,2) AS gebflae, round(st_area(ST_Intersection(b5.wkb_geometry,f5.wkb_geometry))::numeric,2) AS schnittflae, 
 st_within(b5.wkb_geometry,f5.wkb_geometry) as drin, GeometryType(b5.wkb_geometry) as bgeotyp 
 FROM ax_flurstueck f5 
 JOIN ax_bauwerkoderanlagefuersportfreizeitunderholung b5 ON st_intersects(b5.wkb_geometry,f5.wkb_geometry) = true
 LEFT JOIN ax_bauwerksfunktion_bauwerkoderanlagefuersportfreizeitunde k5 ON b5.bauwerksfunktion = k5.wert 
 WHERE f5.gml_id = $1 AND f5.endet IS NULL AND b5.endet IS NULL ";
// 6 - L e i t u n g
$sqlb.="UNION
 SELECT ".$btyp_leitg." AS bwtyp, b6.gml_id, b6.bauwerksfunktion, k6.beschreibung, k6.dokumentation, NULL AS bezeichnung, b6.name, NULL AS gehoertzu, 
 round(st_area(b6.wkb_geometry)::numeric,2) AS gebflae, round(st_area(ST_Intersection(b6.wkb_geometry,f6.wkb_geometry))::numeric,2) AS schnittflae, 
 st_within(b6.wkb_geometry,f6.wkb_geometry) as drin, GeometryType(b6.wkb_geometry) as bgeotyp 
 FROM ax_flurstueck f6 
 JOIN ax_leitung b6 ON st_intersects(b6.wkb_geometry,f6.wkb_geometry) = true
 LEFT JOIN ax_bauwerksfunktion_leitung k6 ON b6.bauwerksfunktion = k6.wert 
 WHERE f6.gml_id = $1 AND f6.endet IS NULL AND b6.endet IS NULL ";
// 7 - T r a n s p o r t a n l a g e
$sqlb.="UNION
 SELECT ".$btyp_trans." AS bwtyp, b7.gml_id, b7.bauwerksfunktion, k7.beschreibung, k7.dokumentation, NULL AS bezeichnung, NULL AS name, NULL AS gehoertzu, 
 round(st_area(b7.wkb_geometry)::numeric,2) AS gebflae, round(st_area(ST_Intersection(b7.wkb_geometry,f7.wkb_geometry))::numeric,2) AS schnittflae, 
 st_within(b7.wkb_geometry,f7.wkb_geometry) as drin, GeometryType(b7.wkb_geometry) as bgeotyp 
 FROM ax_flurstueck f7 
 JOIN ax_transportanlage b7 ON st_intersects(b7.wkb_geometry,f7.wkb_geometry) = true
 LEFT JOIN ax_bauwerksfunktion_transportanlage k7 ON b7.bauwerksfunktion = k7.wert 
 WHERE f7.gml_id = $1 AND f7.endet IS NULL AND b7.endet IS NULL ";
// 8 - T u r m  (Sonderfall Array)
$sqlb.="UNION
 SELECT ".$btyp_turm." AS bwtyp, b8.gml_id, k8.wert AS bauwerksfunktion, k8.beschreibung, k8.dokumentation, NULL AS bezeichnung, b8.name, NULL AS gehoertzu, 
 round(st_area(b8.wkb_geometry)::numeric,2) AS gebflae, round(st_area(ST_Intersection(b8.wkb_geometry,f8.wkb_geometry))::numeric,2) AS schnittflae, 
 st_within(b8.wkb_geometry,f8.wkb_geometry) as drin, GeometryType(b8.wkb_geometry) as bgeotyp 
 FROM ax_flurstueck f8 
 JOIN ax_turm b8 ON st_intersects(b8.wkb_geometry,f8.wkb_geometry) = true
 LEFT JOIN ax_bauwerksfunktion_turm k8 ON k8.wert =ANY(b8.bauwerksfunktion)
 WHERE f8.gml_id = $1 AND f8.endet IS NULL AND b8.endet IS NULL ";
// 9 -  V o r r a t s b e h ä l t e r ,  S p e i c h e r b a u w e r k
$sqlb.="UNION
 SELECT ".$btyp_vorrat." AS bwtyp, b9.gml_id, b9.bauwerksfunktion, k9.beschreibung, k9.dokumentation, NULL AS bezeichnung, b9.name, NULL AS gehoertzu, 
 round(st_area(b9.wkb_geometry)::numeric,2) AS gebflae, round(st_area(ST_Intersection(b9.wkb_geometry,f9.wkb_geometry))::numeric,2) AS schnittflae, 
 st_within(b9.wkb_geometry,f9.wkb_geometry) as drin, GeometryType(b9.wkb_geometry) as bgeotyp 
 FROM ax_flurstueck f9 
 JOIN ax_vorratsbehaelterspeicherbauwerk b9 ON st_intersects(b9.wkb_geometry,f9.wkb_geometry) = true
 LEFT JOIN ax_bauwerksfunktion_vorratsbehaelterspeicherbauwerk k9 ON b9.bauwerksfunktion = k9.wert 
 WHERE f9.gml_id = $1 AND f9.endet IS NULL AND b9.endet IS NULL ";
// 10 - H i s t o r i s c h e s  Bauwerk oder historische Einrichtung
$sqlb.="UNION
 SELECT ".$btyp_hist." AS bwtyp, b10.gml_id, NULL AS bauwerksfunktion, k10.beschreibung, k10.dokumentation, NULL AS bezeichnung, b10.name, NULL AS gehoertzu, 
 round(st_area(b10.wkb_geometry)::numeric,2) AS gebflae, round(st_area(ST_Intersection(b10.wkb_geometry,f10.wkb_geometry))::numeric,2) AS schnittflae, 
 st_within(b10.wkb_geometry,f10.wkb_geometry) as drin, GeometryType(b10.wkb_geometry) as bgeotyp 
 FROM ax_flurstueck f10 
 JOIN ax_historischesbauwerkoderhistorischeeinrichtung b10 ON st_intersects(b10.wkb_geometry,f10.wkb_geometry) = true
 LEFT JOIN ax_archaeologischertyp_historischesbauwerkoderhistorischee k10 ON b10.archaeologischertyp = k10.wert 
 WHERE f10.gml_id = $1 AND f10.endet IS NULL AND b10.endet IS NULL ";
// 11 - H e i l q u e l l e ,   G a s q u e l l e
$sqlb.="UNION
 SELECT ".$btyp_heil." AS bwtyp, b11.gml_id, NULL AS bauwerksfunktion, k11.beschreibung, k11.dokumentation, NULL AS bezeichnung, b11.name, NULL AS gehoertzu, 
 round(st_area(b11.wkb_geometry)::numeric,2) AS gebflae, round(st_area(ST_Intersection(b11.wkb_geometry,f11.wkb_geometry))::numeric,2) AS schnittflae, 
 st_within(b11.wkb_geometry,f11.wkb_geometry) as drin, GeometryType(b11.wkb_geometry) as bgeotyp 
 FROM ax_flurstueck f11 
 JOIN ax_heilquellegasquelle b11 ON st_intersects(b11.wkb_geometry,f11.wkb_geometry) = true
 LEFT JOIN ax_art_heilquellegasquelle k11 ON b11.art = k11.wert 
 WHERE f11.gml_id = $1 AND f11.endet IS NULL AND b11.endet IS NULL ";
// 12 - Einrichtung in öffentlichen Bereichen
$sqlb.="UNION
 SELECT ".$btyp_oeff." AS bwtyp, b12.gml_id, NULL AS bauwerksfunktion, k12.beschreibung, k12.dokumentation, NULL AS bezeichnung, NULL AS name, NULL AS gehoertzu, 
 round(st_area(b12.wkb_geometry)::numeric,2) AS gebflae, round(st_area(ST_Intersection(b12.wkb_geometry,f12.wkb_geometry))::numeric,2) AS schnittflae, 
 st_within(b12.wkb_geometry,f12.wkb_geometry) as drin, GeometryType(b12.wkb_geometry) as bgeotyp 
 FROM ax_flurstueck f12 
 JOIN ax_einrichtunginoeffentlichenbereichen b12 ON st_intersects(b12.wkb_geometry,f12.wkb_geometry) = true
 LEFT JOIN ax_art_einrichtunginoeffentlichenbereichen k12 ON b12.art = k12.wert 
 WHERE f12.gml_id = $1 AND f12.endet IS NULL AND b12.endet IS NULL ";
/* Testfälle FS: SELECT f.gml_id FROM ax_flurstueck f JOIN ax_einrichtunginoeffentlichenbereichen b ON st_intersects(b.wkb_geometry,f.wkb_geometry) = true;
 140: DENW17AL34g000F6  */

/* // 13 - Besonderer Bauwerkspunkt (ohne Geometrie !)
$sqlb.="UNION
 SELECT ".$btyp_bpkt.
// Tab: ax_besondererbauwerkspunkt */

// Generell ...
$sqlb.="ORDER BY bwtyp, schnittflae DESC;";

$v=array($gmlid);
$resb=pg_prepare($con, "", $sqlb);
$resb=pg_execute($con, "", $v);

$baunr=0; // Zähler
$bauflsum=0; // Flächensumme
$gwbwtyp=0; // Gruppen-Wechsel Bauwerks-Typ

if (!$resb) {
	echo "\n<p class='err'>Fehler bei Bauwerke-Verschneidung.</p>";
	if ($dbg > 2) {echo "\n<p class='err'>SQL=<br>".str_replace("$1","'".$gmlid."'",$sqlb)."</p>";}
	if ($dbg > 1) {echo "<p class='dbg'>Fehler:".pg_result_error($resb)."</p>";}
} else {
	while($rowb = pg_fetch_assoc($resb)) {
		$baunr++;
		$btyp=$rowb["bwtyp"]; // Tabelle
		$bgml=$rowb["gml_id"];
		$bauflsum=$bauflsum + $rowb["schnittflae"];
		if (is_null($rowb["bezeichnung"])) {
			$bbez="";
		} else {
			$bbez=htmlentities($rowb["bezeichnung"], ENT_QUOTES, "UTF-8");
		}
		$bfktk=htmlentities($rowb["bauwerksfunktion"], ENT_QUOTES, "UTF-8");
		$bfktv=htmlentities($rowb["beschreibung"], ENT_QUOTES, "UTF-8");
		$bfktd=htmlentities($rowb["dokumentation"], ENT_QUOTES, "UTF-8"); 
		if (is_null($rowb["name"])) {
			$bnam="";
		} else {
			$bnam=htmlentities($rowb["name"], ENT_QUOTES, "UTF-8");
		}
		$bgeb=$rowb["gehoertzu"];
		$drin=$rowb["drin"];
		$bgeotyp=$rowb["bgeotyp"];

		// Lage des Bauwerks zum Flurstück
		if ($bgeotyp === "LINESTRING") {
			if ($drin === "t") {
				$bstyle="gin";
			} else {
				$bstyle="gtl";
			}
			$f1="&nbsp;";
			$f2="Linie";
		} elseif ($bgeotyp === "POINT") {
			if ($drin === "t") {
				$bstyle="gin";
			} else {
				$bstyle="gtl";
			}
			$f1="&nbsp;";
			$f2="Punkt";		
		} else { // Fläche / Multi-
			if ($drin === "t") { // komplett IM Flurstück
				$f1=$rowb["schnittflae"]." m&#178;";
				$f2="&nbsp;";
				$bstyle="gin"; // siehe .css
			} else {
				if ($rowb["schnittflae"] === "0.00") { // nur angrenzend
					$bstyle="gan";
					$f1="&nbsp;";
					$f2="angrenzend";
				} else { // Teile auf Flurstück
					$bstyle="gtl";
					$f1=$rowb["schnittflae"]." m&#178;";
					$f2="(von ".$rowb["gebflae"]." m&#178;)";
				}
			}	
		}

		// Gruppenwechsel Bauwerks-Typ (Quell-Tabelle) - Zwischen-Überschrift
		If ($btyp != $gwbwtyp) {
			$gwbwtyp = $btyp;
			if ($baunr === 1) {bauw_tab_head();} // Tab.-Kopf
			switch ($btyp) {
				case $btyp_verkehr:
					$btyptitle='Bauwerk im Verkehrsbereich'; break;
				case $btyp_gewaesser:
					$btyptitle='Bauwerk im Gew&auml;sserbereich'; break;
				case $btyp_sonst: 
					$btyptitle='Sonstiges Bauwerk oder sonstige Einrichtung'; break;
				case $btyp_indu:
					$btyptitle="Bauwerk oder Anlage f&uuml;r Industrie und Gewerbe"; break;
				case $btyp_sport:
					$btyptitle="Bauwerk oder Anlage f&uuml;r Sport, Freizeit und Erholung"; break;
				case $btyp_leitg:
					$btyptitle="Leitung"; break;
				case $btyp_trans:
					$btyptitle="Transportanlage"; break;
				case $btyp_turm:
					$btyptitle="Turm"; break;
				case $btyp_vorrat:
					$btyptitle="Vorratsbeh&auml;lter, Speicherbauwerk"; break;
				case $btyp_hist:
					$btyptitle="Historisches Bauwerk oder historische Einrichtung"; break;
				case $btyp_heil:
					$btyptitle="Heilquelle, Gasquelle"; break;
				case $btyp_oeff:
					$btyptitle="Einrichtung in &ouml;ffentlichen Bereichen"; break;
				case $btyp_bpkt:
					$btyptitle="Besonderer Bauwerkspunkt"; break;
				default:
					$btyptitle='Fehler!'; break;
			}
			echo "<tr><td colspan=3></td><td colspan=2 class='gw'>".$btyptitle."</td></tr>"; // ++ Symbol?
		}

		echo "\n<tr>";
			echo "\n\t<td>";
			if ($bbez != "") {echo "<span title='Bezeichnung'>".$bbez."</span> ";}
			if ($bnam != "") {echo "<span title='Name'>".$bnam."</span> ";}
			echo "</td>";
			echo "\n\t<td class='fla'>".$f1."</td>"
			."\n\t<td class='".$bstyle."'>".$f2."</td>"; // Flächenangaben
			echo "\n\t<td>".DsKy($bfktk, 'Bauwerksfunktion-*')."<span title='".$bfktd ."'>".$bfktv."</span></td>";

			// Lage / Haus (nur bei Typ 3 sonstige)
			echo "\n\t<td class='nwlink noprint'>"; // Link
			if ($bgeb != "") { // gehört zu Gebäude
			//	bw_gz_lage($bgeb); // Function: Lage (Adresse) ausgeben
				echo "\n\t\t<a title='gehört zu' href='alkishaus.php?gkz=".$gkz."&amp;gmlid=".$bgeb.LnkStf()
				."'>Haus&nbsp;<img src='ico/Haus.png' width='16' height='16' alt=''></a>";
			}
			echo "\n\t</td>";

			// Bauwerk Details
			echo "\n\t<td class='nwlink noprint'>" // Link
			."\n\t\t<a title='Bauwerksdaten' href='alkisbauwerk.php?gkz=".$gkz."&amp;btyp=".$btyp."&amp;gmlid=".$bgml.LnkStf()
			."'>Bauwerk&nbsp;<img src='ico/Haus.png' width='16' height='16' alt=''></a>" // Icon für Bauwerk schaffen
			."\n\t</td>"
		."\n</tr>";
	}

	// Footer Bauwerke
	if ($baunr === 0) {
		echo "\n<p>Kein Bauwerk auf diesem Flurst&uuml;ck.</p><br>";
		// if ($dbg > 2) {echo "\n<p class='err'>SQL=<br>".$sqlb."<br>$1 = gml_id = '".$gmlid."'</p>";}
	} else {
		echo "\n<tr>"
			."\n\t<td>Summe:</td>"
			."\n\t<td class='fla sum' title='von Bauwerken &uuml;berbaute Fl&auml;che des Flurst&uuml;cks'>".number_format($bauflsum,0,",",".")."&nbsp;&nbsp;&nbsp;&nbsp;m&#178;</td>"
			."\n\t<td colspan='6'>&nbsp;</td>"
		."\n</tr>"
		."\n</table>\n";
	}
	pg_free_result($resb);
}

echo "<div class='buttonbereich noprint'>\n<hr>"
	."\n\t<a title='zur&uuml;ck' href='javascript:history.back()'><img src='ico/zurueck.png' width='16' height='16' alt='zur&uuml;ck'></a>&nbsp;";
if ($PrntBtn==true){echo "\n\t<a title='Drucken' href='javascript:window.print()'><img src='ico/print.png' width='16' height='16' alt='Drucken'></a>&nbsp;";}
echo "\n</div>";

footer($gmlid, selbstverlinkung()."?", "");
?>
</body>
</html>

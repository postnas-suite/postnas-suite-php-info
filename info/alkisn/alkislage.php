<?php
/*	alkislage.php

	ALKIS-Auskunft
	Author: Frank Jäger, Kommunales Rechenzentrum Minden-Ravensberg/Lippe (Lemgo)

	Kann die 3 Arten von Lagebezeichnung anzeigen und verbundene Objekte verlinken

	Version:
	2016-02-24 Version für norGIS-ALKIS-Import
	....
	2020-02-20 Authentifizierung ausgelagert in Function darf_ich()
	2020-12-03 function selbstverlinkung() statt $_SERVER['PHP_SELF']
	2020-12-15 Input-Validation und Strict Comparisation (===)
	2021-12-09 Neue Parameter: $katAmtMix (Kataster-Amt-Mix), $PrntBtn (Drucken-Schaltfläche)
               Gemarkung und Flur bei Gruppenwechsel FETT anzeigen
	2022-01-12 Neue Functions LnkStf(), DsKy()
	2022-07-05 PHP 8.1: Connection verwenden bei "pg_prepare" und "pg_execute", keine NULL-Werte in String-Functions verwenden
	
ToDo:
	- Balken-Kennzeichen kompatibel machen mit der Eingabe der Navigation für Adresse
	- das Modul "alkisgebaeudenw" (alle Geb. auf einem FS) verschneidet die Flächen und findet damit auch 
	Grenz-Überbauungen und angrenzende Gebäude. Diese fehlen hier, weil nur Verknüpfungen verarbeitet werden.
	Mit Flächen-Verschneidung auch weitere FS anzeigen?
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
if (!preg_match('#^[m|p|o]{1}$#', $ltyp)) {die ("Eingabefehler ltyp");}
if (!preg_match('#^j{0,1}$#', $nodebug)) {die("Eingabefehler nodebug");}

include "alkis_conf_location.php";
include "alkisfkt.php";

switch ($ltyp) {
	case "m": // "Mit HsNr" = Hauptgebäude
		$tnam = "ax_lagebezeichnungmithausnummer"; break;
	case "p": // "mit PseudoNr" = Nebengebäude
		$tnam = "ax_lagebezeichnungmitpseudonummer"; break;
	case "o": //"Ohne HsNr" = Gewanne oder Straße
		$tnam = "ax_lagebezeichnungohnehausnummer"; break;
	default:
		$ltyp = "m";
		$tnam = "ax_lagebezeichnungmithausnummer"; break;
}
echo <<<END
<!doctype html>
<html lang="de">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>ALKIS Lagebezeichnung</title>
	<link rel="stylesheet" type="text/css" href="alkisauszug.css">
	<link rel="shortcut icon" type="image/x-icon" href="ico/Lage_mit_Haus.ico">
</head>
<body>
END;

$erlaubnis = darf_ich(); if ($erlaubnis === 0) { die('<p class="stop1">Abbruch</p></body>'); }
$dbg=$debug;
if ($nodebug === "j") {$dbg=0;} 

$con = pg_connect($dbconn." options='--application_name=ALKIS-Auskunft_alkislage.php'");
if (!$con) echo "\n<p class='err'>Fehler beim Verbinden der DB</p>";

// L a g e b e z e i c h n u n g
$sql ="SELECT s.gml_id AS strgml, s.bezeichnung AS snam, b.bezeichnung AS bnam, r.bezeichnung AS rnam, k.bezeichnung AS knam, g.bezeichnung AS gnam, l.land, l.regierungsbezirk, l.kreis, l.gemeinde, l.lage, ";
switch ($ltyp) {
	case "m": // "Mit HsNr"
		$sql.="l.hausnummer ";
	break;
	case "p": // "mit PseudoNr"
		$sql.="l.pseudonummer, l.laufendenummer ";
	break;
	case "o": //"Ohne HsNr"
		$sql.="l.unverschluesselt ";
	break;
}
// "Left" weil: Bei sub-Typ "Gewanne" von Typ "o" sind keine Schlüsselfelder gefüllt!
$sql.="FROM ".$tnam." l 
LEFT JOIN ax_gemeinde g ON l.land=g.land AND l.regierungsbezirk=g.regierungsbezirk AND l.kreis=g.kreis AND l.gemeinde=g.gemeinde ".UnqKatAmt("l","g")
."LEFT JOIN ax_kreisregion k ON l.land=k.land AND l.regierungsbezirk=k.regierungsbezirk AND l.kreis=k.kreis ".UnqKatAmt("l","k")
."LEFT JOIN ax_regierungsbezirk r ON l.land=r.land AND l.regierungsbezirk=r.regierungsbezirk ".UnqKatAmt("l","r")
."LEFT JOIN ax_bundesland b ON l.land=b.land ".UnqKatAmt("l","b")
."LEFT JOIN ax_lagebezeichnungkatalogeintrag s 
ON l.land=s.land AND l.regierungsbezirk=s.regierungsbezirk AND l.kreis=s.kreis AND l.gemeinde=s.gemeinde AND l.lage=s.lage 
WHERE l.gml_id= $1 AND l.endet IS NULL AND g.endet IS NULL AND k.endet IS NULL AND r.endet IS NULL AND b.endet IS NULL AND s.endet IS NULL;";

$v = array($gmlid);
$res = pg_prepare($con, "", $sql);
$res = pg_execute($con, "", $v);
if (!$res) {
	echo "\n<p class='err'>Fehler bei Lagebezeichnung.</p>";
	if ($dbg > 2) {echo "\n<p class='err'>SQL=<br>".htmlentities(str_replace("$1", "'".$gmlid."'", $sql), ENT_QUOTES, "UTF-8")."</p>";}
}
if ($dbg > 0) {
	$zeianz=pg_num_rows($res);
	if ($zeianz > 1){
		echo "\n<p class='err'>Die Abfrage liefert mehr als ein (".$zeianz.") Lage-Objekt!</p>";
		if ($dbg > 2) {echo "\n<p class='dbg'>SQL=<br>".htmlentities(str_replace("$1","'".$gmlid."'",$sql), ENT_QUOTES, "UTF-8")."</p>";}
	}
}
if ($row = pg_fetch_assoc($res)) {
	$strgml=$row["strgml"]; // gml_id des Katalogeintrag Straße
	$land =$row["land"];
	$regbez=$row["regierungsbezirk"];
	$kreis=$row["kreis"];
	$knam=$row["knam"];
	$rnam=$row["rnam"];
	$bnam=$row["bnam"];
	$gem=$row["gemeinde"];
	$gnam=$row["gnam"];
	$lage=$row["lage"]; // Strassenschluessel
	$snam=$row["snam"]; //Strassennamen
//	$kennz=$land."-".$regbez."-".$kreis. ..
	$kennz=$gem."-".$lage."-"; // ToDo: Kompatibel machen als Eingabe in Navigation/Adresse 

	switch ($ltyp) {
		case "m": // "Mit HsNr"
			$hsnr=$row["hausnummer"];
			$kennz.=$hsnr;
			$untertitel="Hauptgeb&auml;ude mit Hausnummer";
			echo "\n<p class='balken lage'>ALKIS Lagebezeichnung mit Hausnummer ".$kennz."&nbsp;</p>"; // Balken
			$osub="";
		break;
		case "p": // "mit PseudoNr"
			$pseu=$row["pseudonummer"];
			$lfd=$row["laufendenummer"];
			$kennz.=$pseu."-".$lfd;
			$untertitel="Nebengebäude mit laufender Nummer (Lagebezeichnung mit Pseudonummer)";
			echo "\n<p class='balken lage'>ALKIS Lagebezeichnung Nebengebäude ".$kennz."&nbsp;</p>"; // Balken
			$osub="";
		break;
		case "o": // "Ohne HsNr"
			$unver=$row["unverschluesselt"]; // Gewanne
			// 2 Unterarten bzw. Zeilen-Typen in der Tabelle
			if ($lage == "") {
				$osub="g"; // Sub-Typ Gewanne
				$kennz=" - ".$unver;
				$untertitel="Gewanne (unverschl&uuml;sselte Lage)";
				echo "\n<p class='balken lage'>ALKIS Lagebezeichnung Ohne Hausnummer ".$kennz."&nbsp;</p>"; // Balken
			} else {
				$osub="s"; // Sub-Typ Strasse (ohne HsNr)
				$kennz.=$unver;
				$untertitel="Stra&szlig;e ohne Hausnummer";
				echo "\n<p class='balken lage'>ALKIS Lagebezeichnung Ohne Hausnummer ".$kennz."&nbsp;</p>"; // Balken
			}
		break;
	}
} else {
	echo "\n<p class='err'>Fehler! Kein Treffer fuer Lagebezeichnung mit gml_id='".$gmlid."'</p>";
	if ($dbg > 2) {
		echo "\n<p class='err'>SQL=<br>".htmlentities(str_replace("$1", "'".$gmlid."'", $sql), ENT_QUOTES, "UTF-8")."</p>";
	}
	echo "\n</body>\n</html>";
	exit;
}

echo "\n<h2>Lagebezeichnung</h2>\n<p>Typ: ".$untertitel."</p>";
echo "\n<table class='outer'>\n<tr>\n\t<td>"; 	// Tab. Kennz.
	// ToDo: kleiner, wenn ltyp=0 und die Schlüsselfelder leer sind
	echo "\n\t\t<table class='kennzla' title='Lage'>"
		."\n\t\t<tr>";
			if ($osub != "g") { // nicht bei Gewanne
				echo "\n\t\t\t<td class='head'>Land</td>"
				."\n\t\t\t<td class='head'>Reg.-Bez.</td>"
				."\n\t\t\t<td class='head'>Kreis</td>"
				."\n\t\t\t<td class='head'>Gemeinde</td>"
				."\n\t\t\t<td class='head'>Stra&szlig;e</td>";
			}
			switch ($ltyp) {
				case "m": // "Mit HsNr"
					echo "\n\t\t\t<td class='head'>Haus-Nr</td>";
				break;
				case "p": // "mit PseudoNr"
					echo "\n\t\t\t<td class='head'>Haus-Nr</td>"
					."\n\t\t\t<td class='head'>lfd.-Nr</td>";
				break;
				case "o": //"Ohne HsNr"
					if ($osub === "g") {
						echo "\n\t\t\t<td class='head'>unverschl&uuml;sselte Lage</td>";
					}
				break;
			}
		echo "\n\t\t</tr>\n\t\t<tr>";
			if ($osub != "g") { // nicht bei Gewanne
				echo "\n\t\t\t<td title='Bundesland'>".DsKy($land, 'Bundesland-*').$bnam."&nbsp;</td>"
				."\n\t\t\t<td title='Regierungsbezirk'>".DsKy($regbez, 'Regierungsbezirk-*').$rnam."&nbsp;</td>"
				."\n\t\t\t<td title='Kreis'>".DsKy($kreis, 'Kreis-*').$knam."&nbsp;</td>"
				."\n\t\t\t<td title='Gemeinde'>".DsKy($gem, 'Gemeinde-*').$gnam."&nbsp;</td>"
				."\n\t\t\t<td title='Stra&szlig;e'>".DsKy($lage, 'Stra&szlig;en-*');
				if ($ltyp === "o") {
					echo "<span class='wichtig'>".$snam."</span>";
				} else {
					echo $snam;
				}	
				echo "&nbsp;</td>";
			}

			switch ($ltyp) {
				case "m":
					echo "\n\t\t\t<td title='Hausnummer und Zusatz'><span class='wichtig'>".$hsnr."</span></td>";
				break;
				case "p":
					echo "\n\t\t\t<td title='Pseudonummer - Nebengeb&auml;ude zu dieser Hausnummer'>".$pseu."</td>"
					."\n\t\t\t<td title='Laufende Nummer Nebengeb&auml;ude'><span class='wichtig'>".$lfd."</span></td>";
				break;
				case "o":
					if ($osub === "g") {
						echo "\n\t\t\t<td title='Gewanne'><span class='wichtig'>".$unver."</span></td>";
					}
				break;
			}
		echo "\n\t\t</tr>"
	."\n\t\t</table>";

	echo "\n\t</td>\n\t<td>";

	// Kopf Rechts: weitere Daten?
	if ($osub != "g") { // Link zu Strasse
		echo "\n\t\t<p class='nwlink noprint'>"
			."\n\t\t\t<a href='alkisstrasse.php?gkz=".$gkz."&amp;gmlid=".$strgml.LnkStf()
			."' title='Stra&szlig;e'>Stra&szlig;e <img src='ico/Strassen.png' width='16' height='16' alt=''></a>"
		."\n\t\t</p>";
	}

echo "\n\t</td>\n</tr>\n</table>";

// F L U R S T Ü C K E
// ax_Flurstueck  >weistAuf>  ax_LagebezeichnungMitHausnummer
// ax_Flurstueck  >zeigtAuf>  ax_LagebezeichnungOhneHausnummer
// ++ auch Flächenverschneidung?
if ($ltyp != "p") { // Pseudonummer linkt nur Gebäude
	echo "\n\n<a id='fs'></a>\n<h3><img src='ico/Flurstueck.png' width='16' height='16' alt=''> Flurst&uuml;cke</h3>"
	."\n<p>mit dieser Lagebezeichnung.</p>";
	switch ($ltyp) {
		case "m": $bezart="weistauf"; break;
		case "o": $bezart="zeigtauf"; break;
	}

	$sql ="SELECT g.gemarkungsnummer, g.bezeichnung, f.gml_id, f.flurnummer, f.zaehler, f.nenner, f.amtlicheflaeche 
	FROM ax_flurstueck f LEFT JOIN ax_gemarkung g ON f.land=g.land AND f.gemarkungsnummer=g.gemarkungsnummer ".UnqKatAmt("f","g")
	."WHERE $1 = ANY(f.".$bezart.") AND f.endet IS NULL AND g.endet IS NULL 
	ORDER BY f.gemarkungsnummer, f.flurnummer, f.zaehler, f.nenner;";

	$v = array($gmlid);
	$resf = pg_prepare($con, "", $sql);
	$resf = pg_execute($con, "", $v);
	if (!$resf) {
		echo "\n<p class='err'>Fehler bei Flurst&uuml;ck.</p>";
		if ($dbg > 2) {echo "\n<p class='err'>SQL=<br>".htmlentities(str_replace("$1", "'".$gmlid."'", $sql), ENT_QUOTES, "UTF-8")."</p>";}	
	}
	echo "\n<table class='fs'>"
	."\n<tr>"
		."\n\t<td class='heads'>Gemarkung<img src='ico/sort.png' width='10' height='10' alt='' title='Sortierung'></td>"
		."\n\t<td class='heads'>Flur<img src='ico/sort.png' width='10' height='10' alt='' title='Sortierung'></td>"
		."\n\t<td class='heads fsnr' title='Flurst&uuml;cksnummer (Z&auml;hler / Nenner)'><img src='ico/sort.png' width='10' height='10' alt='' title='Sortierung'>Flurst.</td>"
		."\n\t<td class='head fla'>Fl&auml;che</td>"
		."\n\t<td class='head nwlink noprint' title='Verlinkung zu anderen Nachweis-Arten und verbundenen Objekten'>weitere Auskunft</td>"
	."\n</tr>";
	$j=0;
	$zpaar=false;
	$gwgmkg=""; // Gruppenwechsel
	$gwflur="";
	while($rowf = pg_fetch_assoc($resf)) {
		$gmkg=$rowf["bezeichnung"];
		$flur=str_pad($rowf["flurnummer"], 3, "0", STR_PAD_LEFT);
		$fskenn=$rowf["zaehler"]; // Bruchnummer
		if ($rowf["nenner"] != "") {$fskenn.="/".$rowf["nenner"];}
		$flae=number_format($rowf["amtlicheflaeche"],0,",",".") . " m&#178;";

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
			echo "\n\t<td class='fsnr'><span class='wichtig'>".$fskenn."</span></td>"
			."\n\t<td class='fla'>".$flae."</td>"
			."\n\t<td>\n\t\t<p class='nwlink noprint'>"
				."\n\t\t\t<a href='alkisfsnw.php?gkz=".$gkz."&amp;gmlid=".$rowf["gml_id"].LnkStf()."&amp;eig=n"
				."' title='Flurst&uuml;cksnachweis'>Flurst&uuml;ck <img src='ico/Flurstueck_Link.png' width='16' height='16' alt=''></a>"
			."\n\t\t</p>\n\t</td>"
		."\n</tr>";
		$j++;
	}
	echo "\n</table>";
	if ($j > 6) {echo "<p class='cnt'>".$j." Flurst&uuml;cke</p>";}
}

// L A G E
// andere Lage mit gleicher Hausnummer suchen
if ($ltyp != "o") { // nicht bei Gewanne (Ohne HsNr)
	echo "\n\n<a id='lage'></a>\n<h3><img src='ico/Lage_mit_Haus.png' width='16' height='16' alt=''> Lage</h3>"
	."\n<p>Andere Lagebezeichnungen zur gleichen Hausnummer.</p>";
	$whereclaus="WHERE land= $1 AND regierungsbezirk= $2 AND kreis= $3 AND gemeinde= $4 AND lage= $5 ";
	$url=selbstverlinkung()."?gkz=".$gkz.LnkStf()."&amp;gmlid="; // Basis

	switch ($ltyp) {
		case "m": // aktuell: Hausnummer gefunden (Hauptgebäude)
			// dazu alle Nebengebäude suchen
			echo "\n<p>Nebengeb&auml;ude:&nbsp;";
			$sql ="SELECT l.gml_id, l.laufendenummer FROM ax_lagebezeichnungmitpseudonummer l "
			.$whereclaus."AND lage= $6 AND pseudonummer= $7 AND l.endet IS NULL ORDER BY laufendenummer;";

			$v = array($land,$regbez,$kreis,$gem,$lage,$lage,$hsnr);
			$res = pg_prepare($con, "", $sql);
			$res = pg_execute($con, "", $v);
			if (!$res) {
				echo "\n<p class='err'>Fehler bei Nebengeb&auml;ude.</p>";
				if ($dbg > 2) {echo "\n<p class='err'>SQL=<br>".htmlentities($sql, ENT_QUOTES, "UTF-8")."</p>";} // 7 $-Parameter
			}
			$neb=0;
			while($row = pg_fetch_assoc($res)) {
				echo "\n\t<a href='".$url.$row["gml_id"].LnkStf()."&amp;ltyp=p'>lfd.-Nr ".$row["laufendenummer"]."</a>&nbsp;&nbsp;";
				$neb++;
			}
			if ($neb === 0) {echo "keine";}
			echo "</p>";
		break;

		case "p": // aktuell Nebengebäude: Haupt- und (andere) Nebengebäude suchen
			echo "\n<p>Hauptgeb&auml;ude: ";
			$sql ="SELECT l.gml_id FROM ax_lagebezeichnungmithausnummer l ".$whereclaus."AND hausnummer= $6 AND l.endet IS NULL ;";

			$v = array($land,$regbez,$kreis,$gem,$lage,$pseu);
			$res = pg_prepare($con, "", $sql);
			$res = pg_execute($con, "", $v);

			if (!$res) {echo "\n<p class='err'>Fehler bei Hauptgeb&auml;ude.<br>".$sql."</p>";}
			$hg=0;
			while($row = pg_fetch_assoc($res)) {
				echo "\n\t<a href='".$url.$row["gml_id"].LnkStf()."&amp;ltyp=m'>Haus-Nr ".$pseu."</a>&nbsp;&nbsp;";
				$hg++;
			}
			if ($hg === 0) {echo "&nbsp;Kein Hauptgeb&auml;ude gefunden.";}
			echo "</p>";

			echo "\n<p>Weitere Nebengeb&auml;ude:&nbsp;";
			$sql ="SELECT l.gml_id, l.laufendenummer FROM ax_lagebezeichnungmitpseudonummer l "
			.$whereclaus."AND pseudonummer= $6 AND laufendenummer <> $7 AND l.endet IS NULL ORDER BY laufendenummer;";
			$v=array($land,$regbez,$kreis,$gem,$lage,$pseu,$lfd);
			$res = pg_prepare($con, "", $sql);
			$res = pg_execute($con, "", $v);
			if (!$res) {
				echo "\n<p class='err'>Fehler bei Nebengeb&auml;ude.</p>";
				if ($dbg > 2) {echo "\n<p class='err'>SQL=<br>".htmlentities($sql, ENT_QUOTES, "UTF-8")."</p>";} // 7 $-Parameter		
			}
			$neb=0;
			while($row = pg_fetch_assoc($res)) {
				echo "\n\t<a href='".$url.$row["gml_id"].LnkStf()."&amp;ltyp=p'>lfd.-Nr ".$row["laufendenummer"]."</a>&nbsp;&nbsp;";
				$neb++;
			}
			if ($neb === 0) {echo "keine";}
			echo "</p>";
		break;
	}
}

// G E B Ä U D E
if ($ltyp != "o") { // OhneHsNr linkt nur Flurst.
	echo "\n\n<a id='geb'></a>\n<h3><img src='ico/Haus.png' width='16' height='16' alt=''> Geb&auml;ude</h3>"
	."\n<p>mit dieser Lagebezeichnung.</p>";
	switch ($ltyp) {
		case "p": $bezart="g.hat"; break;
		case "m": $bezart="ANY(g.zeigtauf)"; break; // array
	}

	$sql ="SELECT g.gml_id, g.gebaeudefunktion, array_to_string(g.name, ',') AS name, g.bauweise, g.grundflaeche, g.zustand, round(st_area(g.wkb_geometry)::numeric,2) AS flaeche,
	h.beschreibung AS hv, coalesce(h.dokumentation, '') AS hd, u.beschreibung AS uv, coalesce(u.dokumentation, '') AS ud, z.beschreibung AS zv, coalesce(z.dokumentation, '') AS zd FROM ax_gebaeude g 
	LEFT JOIN ax_bauweise_gebaeude h ON g.bauweise = h.wert
	LEFT JOIN ax_gebaeudefunktion u ON g.gebaeudefunktion = u.wert
	LEFT JOIN ax_zustand_gebaeude z ON g.zustand = z.wert
	WHERE $1 = ".$bezart." AND g.endet IS NULL;";
	// Keine Sortierung (ORDER BY) notwendig weil i.d.R. nur ein (Haupt-)Gebäude diese Hausnummer hat.
	// Für weiter Eigenschaften dem Link "Haus" folgen.

	$v = array($gmlid);
	$res = pg_prepare($con, "", $sql);
	$res = pg_execute($con, "", $v);
	if (!$res) {
		echo "\n<p class='err'>Fehler bei Geb&auml;ude.</p>";
		if ($dbg > 2) {echo "\n<p class='err'>SQL=<br>".htmlentities(str_replace("$1", "'".$gmlid."'", $sql), ENT_QUOTES, "UTF-8")."</p>";}
	}
	echo "\n<table class='geb'>"
	."\n<tr>"
		."\n\t<td class='head' title='Name ist der Eigenname oder die Bezeichnung des Geb&auml;udes.'>Name</td>"
		."\n\t<td class='head fla' title='Fl&auml;che'>Fl&auml;che</td>"
		."\n\t<td class='head' title='Geb&auml;udefunktion ist die zum Zeitpunkt der Erhebung vorherrschend funktionale Bedeutung des Geb&auml;udes'>Funktion</td>"
		."\n\t<td class='head' title='Bauweise ist die Beschreibung der Art der Bauweise'>Bauweise</td>"
		."\n\t<td class='head' title='Zustand beschreibt die Beschaffenheit oder die Betriebsbereitschaft von Geb&auml;ude. Diese Attributart wird nur dann optional gef&uuml;hrt, wenn der Zustand des Geb&auml;udes vom nutzungsf&auml;higen Zustand abweicht.'>Zustand</td>"
		."\n\t<td class='head nwlink' title='Komplette Hausdaten'>Hausdaten</td>"
	."\n</tr>";
	$i=0;
	while($row = pg_fetch_assoc($res)) {
		$ggml=$row["gml_id"];
		$gfla=$row["flaeche"];
		$ud=htmlentities($row["ud"], ENT_QUOTES, "UTF-8");
		$hd=htmlentities($row["hd"], ENT_QUOTES, "UTF-8");
		$zd=htmlentities($row["zd"], ENT_QUOTES, "UTF-8");
		echo "\n<tr>"
		."\n\t<td>".$row["name"]."</td>"
		."\n\t<td class='fla'>".$gfla." m&#178;</td>"
		."\n\t<td title='".$ud."'>".DsKy($row["gebaeudefunktion"], 'Geb&auml;udefunktion-*').$row["uv"]."</td>"
		."\n\t<td title='".$hd."'>".DsKy($row["bauweise"], 'Bauweise-*').$row["hv"]."</td>"
		."\n\t<td title='".$zd."'>".DsKy($row["zustand"], 'Zustand-*').$row["zv"]."</td>"
		."\n\t<td class='nwlink noprint'>"
			."\n\t\t<a title='komplette Hausdaten' href='alkishaus.php?gkz=".$gkz."&amp;gmlid=".$ggml.LnkStf()
			."'>Haus <img src='ico/Haus.png' width='16' height='16' alt=''></a>"
		."\n\t</td>\n</tr>";
	}
	echo "\n</table>";
}

echo "<div class='buttonbereich noprint'>\n<hr>"
	."\n\t<a title='zur&uuml;ck' href='javascript:history.back()'><img src='ico/zurueck.png' width='16' height='16' alt='zur&uuml;ck'></a>&nbsp;";
if ($PrntBtn==true){echo "\n\t<a title='Drucken' href='javascript:window.print()'><img src='ico/print.png' width='16' height='16' alt='Drucken'></a>&nbsp;";}
echo "\n</div>";

footer($gmlid, selbstverlinkung()."?", "&amp;ltyp=".$ltyp);
?>

</body>
</html>

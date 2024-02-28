<?php
/*	alkisfsnw.php

	ALKIS-Auskunft
	Author: Frank Jäger, Kommunales Rechenzentrum Minden-Ravensberg/Lippe (Lemgo)

	Flurstücksnachweis für ein Flurstückskennzeichen aus ALKIS PostNAS

	Version:
	2016-02-24 Version für norGIS-ALKIS-Import
	...
	2020-12-03 function selbstverlinkung() statt $_SERVER['PHP_SELF']
	2020-12-09 Verschn. mit "Bau-, Raum- oder Bodenordnungsrecht" korrigiert, Entschl. Bodenschätzung korrigiert
	2020-12-16 Input-Validation und Strict Comparisation (===)
	2021-03-09 Link zum Gebäudenachweis auch mit "Bauwerke" betiteln
	2021-12-09 Neue Parameter: $katAmtMix (Kataster-Amt-Mix), $PrntBtn (Drucken-Schaltfläche)
	2022-01-13 Functions in Fach-Modul verschoben, die nicht von mehreren verwendet werden. Neue Functions LnkStf(), DsKy()
	2022-07-05 PHP 8.1: Connection verwenden bei "pg_prepare" und "pg_execute", keine NULL-Werte in String-Functions verwenden

ToDo:
	- Tabbelle "nutz_21" ist ein Relikt von NorGIS/ALB und könnte in späteren Versionen fehlen.
	- Buchung auf "fiktives Blatt" ist nur mit debug zu sehen: die <tr> in .css grau hinterlegen
	- "Umschalter" (gleiches Modul) anders darstellen als Links zu ANDEREN Nachweisen
	- Parameter zum Umschalten mit/ohne Bodenschätzung?
	- Bessere Differenzierung bei den Nutzungsarten (Tabelle dafür aufbauen) 
*/

function werteliste($bez ,$sqlin, $con) {
// Eine oder mehrere Entschlüsselungen in eine Zeile ausgeben.
// Dient dazu, Schlüssel-ARRAYs auflösen ohne die Zeile im JOIN mehrfach aufzulisten
// Anwendung: FS-Nachweis Bodenschätzung
	global $dbg;

	if ($bez === 'e') {$tabelle = 'ax_entstehungsart';}
	elseif ($bez === 's') {$tabelle = 'ax_sonstigeangaben_bodenschaetzung';}

	$sql="SELECT wert, beschreibung FROM ".$tabelle." WHERE wert IN (".$sqlin.") ORDER BY wert LIMIT $1 ;";
	$v = array('9');
	$res = pg_prepare($con, "", $sql);
	$res = pg_execute($con, "", $v);
	if (!$res) {
		echo "\n<p class='err'>Fehler bei Werteliste.</p>";
		if ($dbg > 2) {echo "\n<p class='dbg'>SQL=<br>".htmlentities($sql, ENT_QUOTES, "UTF-8")."</p>";}
		return;
	}
	$zeianz=pg_num_rows($res);
	while($row = pg_fetch_assoc($res)) {
		echo " ".$row["beschreibung"];
	}
	pg_free_result($res);
	if ($zeianz === 0) {
		echo "(kein Treffer)";
		if ($dbg > 2) {echo "\n<p class='dbg'>SQL=<br>".htmlentities($sql, ENT_QUOTES, "UTF-8")."</p>";}
	}
	return;
}

// Start
ini_set("session.cookie_httponly", 1);
session_start();

$eig="n"; $showkey="n"; $nodebug=""; // Var. initalisieren
$cntget = extract($_GET); // alle Parameter in Variable umwandeln

// strikte Validierung aller Parameter
if (isset($gmlid)) {
	if (!preg_match('#^[0-9A-Za-z]{16}$#', $gmlid)) {die("Eingabefehler gmlid");}
	$fskennz='';
} else { // Alternativ
	$gmlid='';
	if (isset($fskennz)) {
		if (!preg_match('#^[0-9\-_/]{8,20}$#', $fskennz)) {die ("Eingabefehler fskennz");}
	} else {
		die("Fehlender Parameter");
	}
}
if (isset($gkz)) {
	if (!preg_match('#^[0-9]{3}$#', $gkz)) {die("Eingabefehler gkz");}
} else {
	die("Fehlender Parameter");
}
if (!preg_match('#^[j|n]{0,1}$#', $eig)) {die("Eingabefehler eig");}
if (!preg_match('#^[j|n]{0,1}$#', $showkey)) {die ("Eingabefehler showkey");}
if ($showkey === "j") {$showkey=true;} else {$showkey=false;} // "j"/"n" als bool, ist praktischer, oft gebraucht
if (!preg_match('#^j{0,1}$#', $nodebug)) {die("Eingabefehler nodebug");}

include "alkis_conf_location.php";
include "alkisfkt.php";
?>
<!doctype html>
<html lang="de">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>ALKIS Flurst&uuml;cksnachweis</title>
	<link rel="stylesheet" type="text/css" href="alkisauszug.css">
	<link rel="shortcut icon" type="image/x-icon" href="ico/Flurstueck.ico">
	<script>
		function ALKISexport(gmlid) {
			window.open('alkisexport.php?gkz=<?php echo $gkz;?>&tabtyp=flurstueck&gmlid=' + gmlid);
		}
	</script>
</head>
<body>
<?php
$erlaubnis = darf_ich(); if ($erlaubnis === 0) { die('<p class="stop1">Abbruch</p></body>'); }
$dbg=$debug;
if ($nodebug === "j") {$dbg=0;} 

$con = pg_connect($dbconn." options='--application_name=ALKIS-Auskunft_alkisfsnw.php'");
if (!$con) echo "\n<p class='err'>Fehler beim Verbinden der DB</p>";

// Ein Flurstücks-Kennzeichen im DB-Format wurde alternativ zur gml_id übermittelt
if ($fskennz != '') {
	// Spalte "flurstueckskennzeichen" ist in DB indiziert. Format z.B.'052647002001910013__' oder '05264700200012______'
	$sql ="SELECT gml_id FROM ax_flurstueck WHERE flurstueckskennzeichen= $1 AND endet IS NULL;";
	$v=array(fskenn_dbformat($fskennz));
	$res = pg_prepare($con, "", $sql);
	$res = pg_execute($con, "", $v);
	if ($row = pg_fetch_assoc($res)) {
		$gmlid=$row["gml_id"];
	} else {
		echo "\n<p class='err'>Fehler! Kein Treffer f&uuml;r Flurst&uuml;ckskennzeichen='".$fskennz."' (".$fskzdb.")</p>";
		echo "<p>Kennzeichen veraltet? <a href='./alkisfshist.php?gkz=".$gkz."&fskennz=".$fskennz.LnkStf()."'>Suche in der Flurst&uuml;cks-Historie</a></p>";
		echo "\n</body>\n</html>";
		return;
	}
	pg_free_result($res);
}

// F L U R S T Ü C K  m. Gebiet
$sql ="SELECT f.zeigtaufexternes_art, f.zeigtaufexternes_name, f.flurnummer, f.zaehler, f.nenner, f.gemeindezugehoerigkeit_regierungsbezirk, f.gemeindezugehoerigkeit_kreis, f.gemeindezugehoerigkeit_gemeinde, f.amtlicheflaeche, st_area(f.wkb_geometry) AS fsgeomflae, 
to_char(cast(f.zeitpunktderentstehung AS date),'DD.MM.YYYY') AS zeitpunktderentstehung, f.istgebucht, g.gemarkungsnummer, g.bezeichnung, r.bezeichnung AS rbez, k.bezeichnung AS kbez, m.bezeichnung AS mbez 
FROM ax_flurstueck f 
LEFT JOIN ax_gemarkung g ON f.gemeindezugehoerigkeit_land=g.land AND f.gemarkungsnummer=g.gemarkungsnummer ".UnqKatAmt("f","g")
."LEFT JOIN ax_regierungsbezirk r ON f.gemeindezugehoerigkeit_regierungsbezirk=r.regierungsbezirk ".UnqKatAmt("f","r")
."LEFT JOIN ax_kreisregion k ON f.gemeindezugehoerigkeit_regierungsbezirk=k.regierungsbezirk AND f.gemeindezugehoerigkeit_kreis=k.kreis ".UnqKatAmt("f","k")
."LEFT JOIN ax_gemeinde m ON m.regierungsbezirk=f.gemeindezugehoerigkeit_regierungsbezirk AND m.kreis=f.gemeindezugehoerigkeit_kreis AND m.gemeinde=f.gemeindezugehoerigkeit_gemeinde ".UnqKatAmt("f","m")
."WHERE f.gml_id= $1 AND f.endet IS NULL AND g.endet IS NULL AND m.endet IS NULL AND k.endet IS NULL AND r.endet IS NULL;";

$v = array($gmlid); // mit gml_id suchen
$res = pg_prepare($con, "", $sql);
$res = pg_execute($con, "", $v);
if (!$res) {
	echo "\n<p class='err'>Fehler bei Flurstuecksdaten</p>";
	if ($dbg > 2) {echo "\n<p class='dbg'>SQL=<br>".htmlentities(str_replace("$1","'".$gmlid."'",$sql), ENT_QUOTES, "UTF-8")."</p>";}
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
	$bezirk=$row["gemeindezugehoerigkeit_regierungsbezirk"];
	$rbez=htmlentities($row["rbez"], ENT_QUOTES, "UTF-8");
	$kreis=$row["gemeindezugehoerigkeit_kreis"];
	$kbez=htmlentities($row["kbez"], ENT_QUOTES, "UTF-8");
	$gemeinde=$row["gemeindezugehoerigkeit_gemeinde"];
	$mbez=htmlentities($row["mbez"], ENT_QUOTES, "UTF-8");
	$flurnummer=$row["flurnummer"];
	$zaehler=$row["zaehler"];
	$nenner=$row["nenner"];
	$flstnummer=$zaehler;
	if ($nenner == NULL) {
		$nenner="";
	} else {
		$flstnummer.="/".$nenner;
	} // BruchNr
	$fsbuchflae=$row["amtlicheflaeche"]; // amtl. Fl. aus DB-Feld
	$fsgeomflae=$row["fsgeomflae"]; // aus Geometrie ermittelte Fläche
	$the_Xfactor = $fsbuchflae / $fsgeomflae; // Multiplikator zur Umrechnung geometrische Abschnittsflächen in Buchfläche
	$fsbuchflaed=number_format($fsbuchflae,0,",",".") . " m&#178;"; // Display-Format dazu
	$fsgeomflaed=number_format($fsgeomflae,0,",",".") . " m&#178;";
	$gml_buchungsstelle=$row["istgebucht"]; // wird erst im GB-Teil benötigt
	$entsteh=$row["zeitpunktderentstehung"];
	$zeart=$row["zeigtaufexternes_art"];
	$zename=$row["zeigtaufexternes_name"];
	if (is_null($zename)) {$zename="";}
} else {
	echo "\n<p class='err'>Fehler! Kein Treffer f&uuml;r Flurst&uuml;ck mit gml_id=".$gmlid."</p>";
	if ($dbg > 2) {echo "\n<p class='dbg'>SQL=<br>".htmlentities(str_replace("$1","'".$gmlid."'",$sql), ENT_QUOTES, "UTF-8")."</p>";}
	echo "\n</body>\n</html>";
	return;
}
pg_free_result($res);

if ($eig==="j") { // Balken
	echo "<p class='balken fsei'>ALKIS Flurst&uuml;ck ".$gmkgnr."-".$flurnummer."-".$flstnummer."&nbsp;</p>"
	."\n\n<h2>Flurst&uuml;ck mit Eigent&uuml;mer</h2>";
} else {
	echo "<p class='balken fskennz'>ALKIS Flurst&uuml;ck ".$gmkgnr."-".$flurnummer."-".$flstnummer."&nbsp;</p>"
	."\n\n<h2>Flurst&uuml;ck</h2>";
}

// Prüfung der Gebiets-Berechtigung bei gemeinsam genutzten Datenbanken (Kreis und Gemeinde)
// Für das gkz (z.B. aus dem Mapfile-Namen) wird in der Konfiguration ein Filter gesetzt.
if ( ($filtkreis != '' and $filtkreis != $kreis) or ($filtgem != '' and $filtgem != $gemeinde) ) {
	// Einer der gesetzten Filter passt nicht
	if ($dbg > 2) {
		echo "\n<p class='err'>Filter Kreis='".$filtkreis."', Gemeinde='".$filtgem."'</p>"
		."\n<p class='err'>Flstk. Kreis='".$fskrs."', Gemeinde='".$fsgem."'</p>";
	}
	echo "\n<br><p class='stop1'>Zugriff nicht erlaubt</p>"
	."\n<br><p class='stop2'>Dies Flurst&uuml;ck liegt ausserhalb der zust&auml;ndigen Stadt oder Gemeinde.</p>\n</body>\n</html>";
	exit;
}

echo "\n<table class='outer'>"
	."\n\t<tr>\n\t\t<td class='ll'><img src='ico/Flurstueck.png' width='16' height='16' alt=''> Kennzeichen:</td>" // Links
	."\n\t\t<td>" // Mitte
	."\n\t\t\t<table class='kennzfs' title='Flurst&uuml;ckskennzeichen'>\n\t\t\t\t<tr>" // darin Tabelle Kennzeichen
		."\n\t\t\t\t\t<td class='head'>Gemarkung</td>\n\t\t\t\t\t<td class='head'>Flur</td>\n\t\t\t\t\t<td class='head'>Flurst-Nr.</td>\n\t\t\t\t</tr>"
		."\n\t\t\t\t<tr>\n\t\t\t\t\t<td title='Gemarkung'>".DsKy($gmkgnr, 'Gemarkungsnummer').$gemkname."&nbsp;</td>"
		."\n\t\t\t\t\t<td title='Flurnummer'>".$flurnummer."</td>"
		."\n\t\t\t\t\t<td title='Flurst&uuml;cksnummer (Z&auml;hler / Nenner)'><span class='wichtig'>".$flstnummer."</span></td>\n\t\t\t\t</tr>"
	."\n\t\t\t</table>"
	."\n\t\t</td>\n\t\t<td>"; // Rechts
	fortfuehrungen($entsteh, $zeart, $zename);
echo "\n\t\t</td>\n\t</tr>\n</table>";

echo "\n<hr>\n<table class='fs'>"; // FS-Teil 6 Spalten
echo "\n<tr>\n\t<td></td>\n\t<td></td>\n\t<td></td>\n\t<td></td>\n\t<td></td>" // 1-5 in erster Zeile kein "colspan" verwenden
	."\n\t<td><p class='nwlink noprint'>weitere Auskunft:</p></td>"
."\n</tr>";

echo "\n<tr>" // Zeile: Gebietszugehörigkeit - Gemeinde / Kreis / Reg.bez.
	."\n\t<td class='ll'><img title='Im Gebiet von' src='ico/Gemeinde.png' width='16' height='16' alt=''> Gebiet:</td>"
	."\n\t<td>Gemeinde<br>Kreis<br>Regierungsbezirk</td>"
	."\n\t<td class='lr' colspan='3'>".DsKy($gemeinde, 'Gemeinde-Nummer').$mbez."<br>".DsKy($kreis, 'Kreis-Nummer').$kbez."<br>".DsKy($bezirk, 'Regierungsbezirk-Nummer').$rbez."</td>"
	."\n\t<td class='nwlink'>";
	if ($fsHistorie){ // conf
		echo "\n\t\t<p class='nwlink noprint'>"
		."\n\t\t\t<a href='alkisfshist.php?gkz=".$gkz."&amp;gmlid=".$gmlid.LnkStf()
		."' title='Vorg&auml;nger-Flurst&uuml;cke'>Historie <img src='ico/Flurstueck_Historisch.png' width='16' height='16' alt=''></a>\n\t\t</p>\n\t";
	}
	echo "</td>"
."\n</tr>";

// L a g e b e z e i c h n u n g

// Lagebezeichnung  M I T  Hausnummer
// ax_flurstueck  >weistAuf>  AX_LagebezeichnungMitHausnummer
$sql="SELECT DISTINCT l.gml_id, l.gemeinde, l.lage, l.hausnummer, s.bezeichnung, s.gml_id AS kgml
FROM ax_flurstueck f JOIN ax_lagebezeichnungmithausnummer l ON l.gml_id=ANY(f.weistauf)  
JOIN ax_lagebezeichnungkatalogeintrag s ON l.land=s.land AND l.regierungsbezirk=s.regierungsbezirk AND l.kreis=s.kreis AND l.gemeinde=s.gemeinde AND l.lage=s.lage 
WHERE f.gml_id= $1 AND f.endet IS NULL AND l.endet IS NULL AND s.endet IS NULL    
ORDER BY l.gemeinde, l.lage, l.hausnummer;";

$v = array($gmlid);
$res = pg_prepare($con, "", $sql);
$res = pg_execute($con, "", $v);

if (!$res) {
	echo "\n<p class='err'>Fehler bei Lagebezeichnung mit Hausnummer</p>";
	if ($dbg > 1) {
		echo "\n<p class='dbg'>Fehler:".pg_last_error()."</p>";
		if ($dbg > 2) {echo "\n<p class='dbg'>SQL=<br>".htmlentities(str_replace("$1","'".$gmlid."'",$sql), ENT_QUOTES, "UTF-8")."</p>";}
	}
} else {
	$j=0;
	$kgmlalt="";
	while($row = pg_fetch_assoc($res)) {
		$sname=htmlentities($row["bezeichnung"], ENT_QUOTES, "UTF-8"); // Str.-Name
		if (substr($sname, strlen($sname) -3, 3) === 'weg') { // Versuch fuer korrekten Satzbau
			$slink=" am ".$sname;
		} else {
			$slink=" an der ".$sname;
		}
		$hsnr=$row["hausnummer"];
		echo "\n<tr>";
			if ($j === 0) {
				echo "\n\t<td class='ll'><img src='ico/Lage_mit_Haus.png' width='16' height='16' alt=''> Adresse:</td>";
			} else {
				echo "\n\t<td>&nbsp;</td>";
			}
			echo "\n\t<td>&nbsp;</td>"
			."\n\t<td class='lr' colspan='3'>".DsKy($row["lage"], 'Straßen-*').$sname."&nbsp;".$hsnr."</td>"
			."\n\t<td>\n\t\t<p class='nwlink noprint'>";

			// +++ davor auch Link "Straße"
			$kgml=$row["kgml"]; // Wiederholung vermeiden
			if ($kgml != $kgmlalt) { // NEUE Strasse vor Lage
				$kgmlalt=$kgml; // Katalog GML-ID
				echo "\n\t\t\t<a title='Flurst&uuml;cke mit oder ohne Hausnummer".$slink."' "
				."href='alkisstrasse.php?gkz=".$gkz."&amp;gmlid=".$kgml.LnkStf()."'>Stra&szlig;e "
				."<img src='ico/Strassen.png' width='16' height='16' alt='STRA'></a> ";
			}

			echo "\n\t\t\t<a title='Flurst&uuml;cke und Geb&auml;ude mit Hausnummer ".$hsnr."' href='alkislage.php?gkz=".$gkz."&amp;ltyp=m&amp;gmlid=".$row["gml_id"].LnkStf()
				."'>Lage <img src='ico/Lage_mit_Haus.png' width='16' height='16' alt=''></a>"
			."\n\t\t</p>\n\t</td>"  // 6
		."\n</tr>";
		$j++;
	}
	$cnt_adressen=$j;
	pg_free_result($res);
}

// Lagebezeichnung  O H N E  Hausnummer  (Gewanne oder nur Strasse)
// ax_flurstueck  >zeigtAuf>  AX_LagebezeichnungOhneHausnummer
$sql ="SELECT l.gml_id, coalesce(l.unverschluesselt, '') AS gewann, l.gemeinde, l.lage, s.bezeichnung 
FROM ax_flurstueck f JOIN ax_lagebezeichnungohnehausnummer l ON l.gml_id=ANY(f.zeigtauf) 
LEFT JOIN ax_lagebezeichnungkatalogeintrag s ON l.land=s.land AND l.regierungsbezirk=s.regierungsbezirk AND l.kreis=s.kreis AND l.gemeinde=s.gemeinde AND l.lage=s.lage 
WHERE f.gml_id = $1 AND f.endet IS NULL AND l.endet IS NULL AND s.endet IS NULL;";

$v = array($gmlid);
$res = pg_prepare($con, "", $sql);
$res = pg_execute($con, "", $v);
if (!$res) {
	echo "\n<p class='err'>Fehler bei Lagebezeichnung ohne Hausnummer</p>";
	if ($dbg > 2) {echo "\n<p class='dbg'>SQL=<br>".htmlentities(str_replace("$1", "'".$gmlid."'", $sql), ENT_QUOTES, "UTF-8")."</p>";}
}
while($row = pg_fetch_assoc($res)) {
	$skey=$row["lage"]; // Strassenschl.
	$lgml=$row["gml_id"]; // Key der Lage
	$gewann = htmlentities($row["gewann"], ENT_QUOTES, "UTF-8");
	if ($gewann != '') {
		echo "\n<tr>"
			."\n\t<td class='ll' title='unverschl&uuml;sselte Lagebezeichnung'><img src='ico/Lage_Gewanne.png' width='16' height='16' alt=''> Gewanne:</td>" // 1
			."\n\t<td></td>"
			."\n\t<td class='lr' colspan='3'>".$gewann."</td>"
			."\n\t<td>\n\t\t<p class='nwlink noprint'>"
				."\n\t\t\t<a title='Flurst&uuml;cke mit der Gewanne ".$gewann."' href='alkislage.php?gkz=".$gkz."&amp;ltyp=o&amp;gmlid=".$lgml.LnkStf()
				."'>\n\t\t\tLage <img src='ico/Lage_Gewanne.png' width='16' height='16' alt=''></a>"
			."\n\t\t</p>\n\t</td>"
		."\n</tr>";
	} elseif ($skey > 0) {
		$sname=htmlentities($row["bezeichnung"], ENT_QUOTES, "UTF-8"); // Str.-Name
		if (substr($sname, strlen($sname) -3, 3) === 'weg') { // Versuch fuer korrekten Satzbau
			$slink=" am ".$sname;
		} else {
			$slink=" an der ".$sname;
		}
		echo "\n<tr>"
			."\n\t<td class='ll'><img src='ico/Lage_an_Strasse.png' width='16' height='16' alt=''> Stra&szlig;e:</td>"
			."\n\t<td></td>\n\t<td class='lr' colspan='3'>".DsKy($skey, 'Straßen-*').$sname."</td>";
			echo "\n\t<td>\n\t\t<p class='nwlink noprint'>"
				."\n\t\t\t<a title='Flurstücke ".$slink."' href='alkislage.php?gkz=".$gkz."&amp;ltyp=o&amp;gmlid=".$lgml.LnkStf()
				."'>\n\t\t\tLage <img src='ico/Lage_an_Strasse.png' width='16' height='16' alt=''>\n\t\t\t</a>"
			."\n\t\t</p>\n\t</td>"
		."\n</tr>";
	}
}
pg_free_result($res);

/* Status "N u t z u n g":
Die Classic-Tabelle "nutzung" ist eine Zusammenfassung aller Tabellen mit Nutzungs-Flächen
Die Classic-Tabelle "nutzung_meta" zeigt die Kategorie und Gruppe des Nutzungs-Abschnitts an.

Aus der norGIS-Struktur wird ersatzweise VORLÄUFIG die Tabelle "nutz_21" verwendet,
die das alte ALB-Format der Nutzungs-Abschnitte von Flurstücken simuliert.
Hier finden sich bereits verschnittene Flächen, aber die gml_id fehlt.

Die Entschlüsselung der Nutzungsart in den verschiedenen ALKIS-Varianten ist darin unterentwickelt.
Diese ist eigentlich für jede der getrennten Tabellen der Gruppe Nutzungsart individuell.
Die Classic-Lösung mit 2 Zusatzfeldern war schon sehr pauschalisiert, aber 
durch die Rück-Konvertierung in ALB-Strukturen in der norGIS-Version ist das zu stark vereinfacht.
z.B. wird "Wohnbaufläche" mit der Zusatzeigenschaft "Art der Bebauung": 'Offen'
nun zur Nutzungsart "Offen".
Durch JOIN auf die "alkis_elemente" mit einem Teil des Schlüssels wird das zur "Wohnbaufläche, Offen".
Es sollte eine Tabellen-Struktur bereit gestellt werden, die auch aussagt, dass der Wert "Offen" zur
Zusatz-Eigenschaft "Art der Bebauung" gehört. Dazu muss das PostProcessing erweitert werden. */

$sql="SELECT trim(both FROM n.nutzsl) AS nutzsl, trim(both FROM n.fl) AS fl, trim(both FROM s.nutzung) AS nutzung
 FROM nutz_21 n JOIN nutz_shl s ON n.nutzsl = s.nutzshl
WHERE n.flsnr = $1 ORDER BY cast(n.fl AS integer) DESC;";
// Flurstueckskennzeichen mit Trennzeichen im ALB-Format wie 'llgggg-fff-zzzzz/nnn'
$fskennzalb=$defland.$gmkgnr."-".str_pad($flurnummer,3,"0",STR_PAD_LEFT)."-".str_pad($zaehler,5,"0",STR_PAD_LEFT)."/".str_pad($nenner,3,"0",STR_PAD_LEFT);
$v = array($fskennzalb);
$res = pg_prepare($con, "", $sql);
$res = pg_execute($con, "", $v);
if (!$res) {
	echo "\n<p class='err'>Fehler bei Suche tats. Nutzung</p>";
	if ($dbg > 2) {echo "\n<p class='dbg'>SQL=<br>".htmlentities(str_replace("$1","'".$fskennzalb."'",$sql), ENT_QUOTES, "UTF-8")."'</p>";}
}
$j=0;
while($row = pg_fetch_assoc($res)) {
	$flae=$row["fl"]; // Buchfläche
	$nutzsl=$row["nutzsl"]; // Schlüssel
	$nutzung=$row["nutzung"]; // Bezeichnung aus ALB-Tabelle, "fein"
	echo "\n<tr>\n\t";
		if ($j === 0) { // 1
			echo "<td class='ll' title='Abschnitt der tats&auml;chlichen Nutzung'><img src='ico/Abschnitt.png' width='16' height='16' alt=''> Nutzung:</td>";
		} else {
			echo "<td>&nbsp;</td>";
		}
		$absflaebuch = number_format($flae,0,",",".") . " m&#178;"; // Formatierte Abschnitts-Buch-Fläche
		echo "\n\t<td></td>"
		."\n\t<td class='fla' title='Buchfl&auml;che des Abschnitts'>".$absflaebuch."</td>" // Sp. wie Fl. in Bodenschätzg.
		."\n\t<td></td>"
		."\n\t<td class='lr' title='Nutzungsart des Abschnitts'>".DsKy($nutzsl, 'Nutzungsarten-*').$nutzung."</td>"
		."\n\t<td>";
/*		// Derzeit ist keine Gruppe zugeordnet
			switch ($grupp) { // Icon nach 4 Objektartengruppen
				case "Siedlung":   $ico = "Abschnitt.png"; break;
				case "Verkehr":	   $ico = "Strassen_Klassifikation.png"; break;
				case "Vegetation": $ico = "Wald.png"; break;
				case "Gewässer":   $ico = "Wasser.png";	break;
				default: $ico = "Abschnitt.png"; break;
			}
			// Icon ist auch im Druck sichtbar, class='noprint' ?		
			echo "<p class='nwlink'><img title='".$title."' src='ico/".$ico."' width='16' height='16' alt='NUA'></p>"; */
		echo "</td>"
	."\n</tr>";
	$j++;
}
pg_free_result($res);

echo "\n<tr>" // Flächen-Summenzeile
	."\n\t<td class='re' title='amtliche Fl&auml;che (Buchfl&auml;che)'>Fl&auml;che:</td>" // 1
	."\n\t<td>&nbsp;</td>" // 2
	."\n\t<td class='fla sum'>" // 3 Flaeche
		."<span title='geometrisch berechnete Fl&auml;che = ".$fsgeomflaed."' class='flae'>".$fsbuchflaed."</span></td>"
	."\n\t<td>&nbsp;</td>" // 4
	."\n\t<td>&nbsp;</td>" // 5
	."\n\t<td>" // 6 Link auf Gebäude-Auswertung
		."\n\t\t<p class='nwlink noprint'>" // Gebaeude-Verschneidung
		."\n\t\t\t<a href='alkisgebaeudenw.php?gkz=".$gkz."&amp;gmlid=".$gmlid.LnkStf();
		if ($cnt_adressen > 0) { // wenn Adresse vorgekommen ist
			echo "' title='Geb&auml;ude oder Bauwerke auf oder an dem Flurst&uuml;ck'>Geb&auml;ude/Bauw.";
		} else { // Gebäude mit Adresse gibt es NICHT, das ist klar
			echo "' title='Suche Geb&auml;ude (ohne Adresse) oder Bauwerke auf dem Flurst&uuml;ck'>Geb&auml;ude/Bauw.";
		}
		echo "<img src='ico/Haus.png' width='16' height='16' alt=''></a>"
		."\n\t\t</p>"
	."\n\t</td>" // 6
	."\n</tr>";

// B o d e n s c h ä t z u n g
// ---------------------------
// Tabelle "klas_3x" (norbit-ALB): Dort fehlen Bodenart und Zustandsstufe, es ist aber bereits auf Buchfläche umgerechnet.

// Bodenschätzungs-Abschnitte mit Flurstücken verschneiden, Spalten entschlüsseln
$sql="SELECT b.nutzungsart AS nutzungsartk, nutzungsart.beschreibung AS nutzungsartv,
 b.bodenart AS bodenartk, bodenart.beschreibung AS bodenartv,
 bodstufe.beschreibung AS bodenstufev, zuststufe.beschreibung AS zuststufev,
 b.entstehungsart AS entstehk, b.klimastufe AS klimastufek, b.wasserverhaeltnisse AS wasserverhk,
 klimastufe.beschreibung AS klimastufev, wasserverh.beschreibung AS wasserverhv,
 b.sonstigeangaben,
 coalesce(b.bodenzahlodergruenlandgrundzahl, '') as bodenzahl, b.ackerzahlodergruenlandzahl AS ackerzahl,
 b.jahreszahl, st_area(st_intersection(b.wkb_geometry, f.wkb_geometry)) AS schnittflae 
FROM ax_flurstueck f
JOIN ax_bodenschaetzung b ON st_intersects(b.wkb_geometry, f.wkb_geometry) AND st_area(st_intersection(b.wkb_geometry, f.wkb_geometry)) > 0.05
LEFT JOIN ax_bodenart_bodenschaetzung bodenart ON b.bodenart = bodenart.wert
LEFT JOIN ax_nutzungsart_bodenschaetzung nutzungsart ON b.nutzungsart = nutzungsart.wert
LEFT JOIN ax_bodenstufe bodstufe ON b.bodenstufe = bodstufe.wert
LEFT JOIN ax_zustandsstufe zuststufe ON b.zustandsstufe = zuststufe.wert
LEFT JOIN ax_klimastufe klimastufe ON b.klimastufe = klimastufe.wert
LEFT JOIN ax_wasserverhaeltnisse wasserverh ON b.wasserverhaeltnisse = wasserverh.wert
WHERE f.gml_id = $1 AND f.endet IS NULL AND b.endet IS NULL ORDER BY schnittflae DESC";

$v = array($gmlid);
$res = pg_prepare($con, "", $sql);
$res = pg_execute($con, "", $v);
if (!$res) {echo "\n<p class='err'>Fehler bei DB-Abfrage zur Klassifizierung Boden</p>\n";}
$gesertragsmz = 0; // Gesamt-ErtragsMesszahl
$klasflae = 0; // Summe klassifizierte Fläche
$j=0;
if(!empty($res) && pg_num_rows($res) > 0) {
	while ($row = pg_fetch_assoc($res)) {
		$nutzungsartk = $row['nutzungsartk']; // Key	-
		$nutzungsartv = $row['nutzungsartv']; // - Value
		if (substr($nutzungsartv, 0, 3) === 'Ack') { // A
			$kbez1="Bodenzahl";
			$kbez2="Ackerzahl";
		} else { // Gr
			$kbez1="Gr&uuml;nlandgrundzahl";
			$kbez2="Gr&uuml;nlandzahl";
		}
		$absflae = $row['schnittflae'];
		$absbuchflae = $absflae * $the_Xfactor;
		$klasflae+=$absbuchflae;

		$boedenzahl=ltrim($row['bodenzahl'], '0');
		if (is_null($row['ackerzahl'])) {
			$ackerzahl="";
			$ertragszahl = 0;	
		} else {
			$ackerzahl=ltrim($row['ackerzahl'], '0');
			$ertragszahl = intval($absbuchflae * $row['ackerzahl'] / 100);
		}
		$gesertragsmz+=$ertragszahl;
	//	$absflaedis = number_format($absflae,0,",",".")." m&#178;"; // als Tool-Tip ?
		$absbuchflaedis = number_format($absbuchflae,0,",",".")." m&#178;";

		$jahr=$row['jahreszahl'];
		$entstehk=$row['entstehk'];
		$klimastufek = $row['klimastufek'];
		$klimastufev = $row['klimastufev'];
		$wasserverhk = $row['wasserverhk'];
		$wasserverhv = $row['wasserverhv'];
		$sonst=$row['sonstigeangaben'];
		if ($j === 0) { // 1
			echo "\n<tr>\n\t<td class='ll' title='Abschnitt Bodensch&auml;tzung'><img src='ico/Landwirt.png' width='16' height='16' alt=''> Bodensch&auml;tzung:</td>";
		} else {
			echo "\n<tr>\n\t<td>&nbsp;</td>";
		}
		echo "\n\t<td class='fla' title='Ertragsmesszahl: Produkt von ".$kbez2."/100 und Fl&auml;che.'>EMZ ".$ertragszahl."</td>"
		."\n\t<td class='re' title='Fl&auml;che des Sch&auml;tzungsabschnitts'>".$absbuchflaedis."</td>"
		."\n\t<td class='lr'><span title='".$kbez1."'>".$boedenzahl."</span>/<span title='".$kbez2."'>".$ackerzahl."</span></td>"
		."\n\t<td class='lr'>";
		echo "\n\t\t".DsKy($nutzungsartk, 'Nutzungsart')." <span title='Nutzungsart-*'>".$nutzungsartv."</span>";
		echo "\n\t\t<br>".DsKy($row['bodenartk'], 'Bodenart-*')." <span title='Bodenart'>".$row['bodenartv']."</span>";

		if (isset($row['bodenstufev'])) {
			echo "\n\t\t<br><span title='Bodenstufe'>".$row['bodenstufev']."</span>";
		} else if (isset($row['zuststufev'])) {
			echo "\n\t\t<br><span title='Zustandsstufe'>".$row['zuststufev']."</span>";
		}

			// Arrays für 'entstehungsart' und 'sonstigeangaben' auflösen;
			// 'klimastufe' und 'wasserverhaeltnisse' liegen als einfache Integer vor
			if (isset($entstehk)) {
				$ent = trim($entstehk, "{}");
				echo "\n\t\t<br><span title='Entstehungsart'>" . DsKy($ent, '*');
				werteliste('e', $ent, $con); // ++ Zeilenweise mit <br> ?
				echo "</span>";
			}
			if (isset($klimastufek) && isset($klimastufev)) {
				echo "\n\t\t<br><span title='Klimastufe'>" . DsKy($klimastufek, '*');
				echo $klimastufev . "</span>";
			}
			if (isset($wasserverhk) && isset($wasserverhv)) {
				echo "\n\t\t<br><span title='Wasserverh&auml;ltnisse'>" . DsKy($wasserverhk, '*');
				echo $wasserverhv . "</span>";
			}
			if (isset($sonst)) {
				$son=trim($sonst, "{}");
				echo "\n\t\t<br><span title='Sonstige Angaben'>".werteliste('s', $son, $con)."</span>"; // ++ Zeilenweise mit <br> ?
			}
			if (isset($jahr)) {
				echo "\n\t\t<br><span title='Jahreszahl'>".$jahr."</span>";
			}
		echo "\n\t</td>"
		."\n\t<td>&nbsp;</td>\n</tr>";
		$j++;
	}
	// Summenzeile
	$klasflaedis = number_format($klasflae,0,",",".")." m&#178;";
	echo "\n<tr>\n\t<td class='re'>Ertragsmesszahl:</td>" // 1
	."\n\t<td class='fla sum' title='Summe der Ertragsmesszahlen f&uuml;r dies Flurst&uuml;ck'>".$gesertragsmz."</td>" // 2
	."\n\t<td class='re'>".$klasflaedis."</td>" // 3
	."\n\t<td colspan='3'>&nbsp;</td>\n</tr>"; // 4-6
}

// H i n w e i s 
// auf  "Bau-, Raum- oder Bodenordnungsrecht" (Baulast, Flurbereinigung) oder eine "strittige Grenze"

// Gemeinsame Fläche suchen: entweder (ST_Intersects and not ST_Touches) oder (2xST_Within OR ST_Overlaps), ST_Intersects liefert auch angrenzende
$sql_boden ="SELECT b.artderfestlegung AS wert, a.beschreibung AS art_verf, b.gml_id AS verf_gml, b.bezeichnung AS verf_bez, 
b.name AS verf_name, d.bezeichnung AS stelle_bez, d.stelle AS stelle_key 
FROM ax_flurstueck f 
JOIN ax_bauraumoderbodenordnungsrecht b ON ST_Within(b.wkb_geometry, f.wkb_geometry) OR ST_Within(f.wkb_geometry, b.wkb_geometry) OR ST_Overlaps(b.wkb_geometry, f.wkb_geometry)
LEFT JOIN ax_artderfestlegung_bauraumoderbodenordnungsrecht a ON b.artderfestlegung=a.wert 
LEFT JOIN ax_dienststelle d ON b.stelle=d.stelle ".UnqKatAmt("b","d")
."WHERE f.gml_id = $1 AND f.endet IS NULL AND b.endet IS NULL AND d.endet IS NULL";

pg_prepare($con, "bodeneuordnung", $sql_boden);
$res_bodeneuordnung = pg_execute($con, "bodeneuordnung", array($gmlid));
if (!$res_bodeneuordnung) {
	echo "\n<p class='err'>Fehler bei Bau-, Raum- oder Bodenordnungsrecht</p>";
	if ($dbg > 2) {echo "\n<p class='dbg'>SQL=<br>".str_replace("$1", "'".$gmlid."'", $sql_boden)."</p>";}
}
$sql_str="SELECT gml_id FROM ax_besondereflurstuecksgrenze WHERE endet IS NULL AND 1000 = ANY(artderflurstuecksgrenze) 
AND ST_touches((SELECT wkb_geometry FROM ax_flurstueck WHERE gml_id = $1 AND endet IS NULL),wkb_geometry);";

pg_prepare($con, "strittigeGrenze", $sql_str);
$res_strittigeGrenze = pg_execute($con, "strittigeGrenze", array($gmlid));
if (!$res_strittigeGrenze) {
	echo "\n<p class='err'>Fehler bei strittige Grenze</p>";
	if ($dbg > 2) {echo "\n<p class='dbg'>SQL=<br>".str_replace("$1", "'".$gmlid."'", $sql_str)."</p>";}
}
if (pg_num_rows($res_bodeneuordnung) > 0 OR pg_num_rows($res_strittigeGrenze) > 0) {
	echo "\n<tr>"
	."\n\t<td title='Hinweise zum Flurst&uuml;ck'><h6><img src='ico/Hinweis.png' width='16' height='16' alt=''> " // 1
	."Hinweise:</h6></td>\n\t<td colspan='5'>&nbsp;</td>\n</tr>";// 2-6
	if (pg_num_rows($res_bodeneuordnung) > 0) {
		while ($row = pg_fetch_assoc($res_bodeneuordnung)) { // 3 Zeilen je Verfahren
			echo "\n<tr title='Bau-, Raum- oder Bodenordnungsrecht'>" // Zeile 1 - kommt immer, darum hier den Link
				."\n\t<td>Bodenrecht</td>" // 1
				."\n\t<td class='re'>Festlegung: </td>" // 2 "Art der Festlegung" zu lang
				."\n\t<td colspan='3'>".DsKy($row['wert'], 'Art des Verfahrens').$row['art_verf']."</td>";
				echo "\n\t<td>"
					."\n\t\t<p class='nwlink noprint'>"
					."\n\t\t\t<a href='alkisbaurecht.php?gkz=".$gkz."&amp;gmlid=".$row['verf_gml'].LnkStf()
					."' title='Bau-, Raum- oder Bodenordnungsrecht'>Recht <img src='ico/Gericht.png' width='16' height='16' alt=''></a>"
					."\n\t\t</p>"		
				."\n\t</td>"
			."\n</tr>";
			$dstell=$row['stelle_key']; // Zeile 2
			if ($dstell != '') {
				echo "\n<tr title='Flurbereinigungsbeh&ouml;rde'>"
					."\n\t<td>&nbsp;</td>" // 1
					."\n\t<td class='re'>Dienststelle: </td>" // 2
					."\n\t<td colspan='3'>".DsKy($dstell, 'Art der Dienststelle').$row['stelle_bez']."</td>"
					."\n\t<td>&nbsp;</td>"
				."\n</tr>";
			}
			$vbez=$row['verf_bez']; // Zeile 3, ist nicht immer gefüllt
			$vnam=$row['verf_name']; // noch seltener
			if ($vbez != '') {
				echo "\n<tr title='Verfahrensbezeichnung'>"
					."\n\t<td>&nbsp;</td>"
					."\n\t<td class='re'>Verfahren: </td>"
					."\n\t<td colspan='3'>";
					if ($vnam == "") {
						echo $vbez; // nur die Nummer
					} else { // Name oder beides
						echo DsKy($vbez, 'Nummer des Verfahrens').$vnam;
					}
		 			echo "</td>"
					."\n\t<td>&nbsp;</td>"
				."\n</tr>";
			}
		}
	}
	if (pg_num_rows($res_strittigeGrenze) > 0) { // 1 Zeile
		echo "\n<tr>\n<td>Strittige Grenze:</td>"
		."<td colspan=4>Mindestens eine Flurst&uuml;cksgrenze ist als <b>strittig</b> zu bezeichnen. Sie kann nicht festgestellt werden, weil die Beteiligten sich nicht &uuml;ber den Verlauf einigen. Nach sachverst&auml;ndigem Ermessen der Katasterbeh&ouml;rde ist anzunehmen, dass das Liegenschaftskataster nicht die rechtm&auml;&szlig;ige Grenze nachweist.</td>"
		."\n<td>&nbsp;</td>\n</tr>";
	}
}
echo "\n</table>";

// B U C H U N G S S T E L L E N  zum FS
$bartgrp="";	// Buchungsart
$barttypgrp="";	// Buchungsart Typ
if ($gml_buchungsstelle === '') {
		echo "\n<p class='err'>Keine Buchungstelle zum Flurst&uuml;ck gefunden.</p>"; // keine Verweis vorhanden?
} else {
	echo "\n<table class='outer'>"; // ALLE Buchungen und Eigentümer in 4 Spalten EINER Tabelle ausgeben
	
	$stufe=1; // Schleifenzähler Tiefe
	$gezeigt=buchung_anzg($gml_buchungsstelle, $eig, false, $gmlid, 1); // die ("dienende") Buchung anzeigen, wenn nicht fiktiv. Liefert 1/0

	$anzber=ber_bs_zaehl($gml_buchungsstelle);	// Anzahl berechtigte Buchungen (nächste Stufe) zu dieser Buchung ermitteln
	$verf_next = array($gml_buchungsstelle);	// Start Recursion mit einem Element
	//if ($dbg > 1) {echo "<p class='dbg'>Nach Stufe ".$stufe.", Anzahl: ". $anzber ."</p>";}

	while ($anzber > 0 ) { // Stufe - recursiv in die Tiefe, solange es was zu verfolgen gibt
		$verf_akt=$verf_next; // die nächste Stufe als aktuell übernehmen ..
		$verf_next=array(); // .. und zum Auffüllen leeren
 		$stufe++;
		foreach($verf_akt as $gml_ber_bs) {
			if (ber_bs_zaehl($gml_ber_bs) > 0) {
				$verf_neu=ber_bs_anzg($gml_ber_bs, $eig, false, $gmlid, ""); // Anzeige ber. Buchungst., ggf. mit Eigentümer.
				$anz_neu=count($verf_neu); // Das Ergebnis zählen
				if ($anz_neu > 0) { // wenn neue geliefert
					$verf_next=array_merge($verf_next, $verf_neu); // die neuen an die Sammlung heften
				}
			}
		} // Ende Buchungs-Array in der Stufe
		$anzber=count($verf_next); // Sammlung auf Stufe zählen, Steuert die Schleife.
	} // Ende Stufe

	echo "\n</table>\n\n";

	// Fehler aus "Modellschwäche" erkennen.
	// Wenn der Verweis der Buchungsstelle auf ein Grundbuch ins Leere läuft, weil das Grundbuch 
	//  nicht im Sekundärbestand vorhanden ist, dann könnte das am NBA-Verfahren liegen.
	if ( $gezeigt === 0 and $stufe === 1 ) {
		echo "<p class='err'>Das Grundbuch zur Buchung '".$gml_buchungsstelle."' fehlt in der Datenbank.</p>";
		if ($dbg > 2) { // fehlt die Buchung?
			echo "<p class='dbg'>Suchen mit SQL: SELECT * FROM ax_buchungsstelle WHERE gml_id='".$gml_buchungsstelle."'; </p>";
		}
	}
}

pg_close($con);
echo "<div class='buttonbereich noprint'>\n<hr>"
	."\n\t<a title='zur&uuml;ck' href='javascript:history.back()'><img src='ico/zurueck.png' width='16' height='16' alt='zur&uuml;ck'></a>&nbsp;";
if ($PrntBtn==true){echo "\n\t<a title='Drucken' href='javascript:window.print()'><img src='ico/print.png' width='16' height='16' alt='Drucken'></a>&nbsp;";}
echo "\n\t<a title='Export als CSV' href='javascript:ALKISexport(\"".$gmlid."\")'><img src='ico/download_fs.png' width='32' height='16' alt='Export'></a>&nbsp;\n</div>";

footer($gmlid, selbstverlinkung()."?", "&amp;eig=".$eig);
?>
</body>
</html>

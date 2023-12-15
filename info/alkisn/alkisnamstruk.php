<?php
/*	alkisnamstruk.php

	ALKIS-Auskunft
	Author: Frank Jäger, Kommunales Rechenzentrum Minden-Ravensberg/Lippe (Lemgo)

	Namens- und Adressdaten für einen Eigentümer aus ALKIS PostNAS

	Version:
	2016-02-24 Version für norGIS-ALKIS-Import
	...
	2020-02-20 Authentifizierung ausgelagert in Function darf_ich()
	2020-12-03 function selbstverlinkung() statt $_SERVER['PHP_SELF']
	2020-12-15 Input-Validation und Strict Comparisation (===)
	2021-12-09 Neue Parameter: $katAmtMix (Kataster-Amt-Mix), $PrntBtn (Drucken-Schaltfläche). Gruppenwechsel Bezirk.
	2022-01-13 Neue Functions LnkStf(), DsKy()
	2022-07-05 PHP 8.1: Connection verwenden bei "pg_prepare" und "pg_execute", keine NULL-Werte in String-Functions verwenden
*/
ini_set("session.cookie_httponly", 1);
session_start();
$multiadress="n"; $showkey="n"; $nodebug=""; // Var. aus Parameter initalisieren
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
if (!preg_match('#^[j|n]{0,1}$#', $multiadress)) {die ("Eingabefehler multiadress");}
if (!preg_match('#^j{0,1}$#', $nodebug)) {die("Eingabefehler nodebug");}

require_once("alkis_conf_location.php");
include("alkisfkt.php");
?>
<!doctype html>
<html lang="de">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>ALKIS Person und Adresse</title>
	<link rel="stylesheet" type="text/css" href="alkisauszug.css">
	<link rel="shortcut icon" type="image/x-icon" href="ico/Eigentuemer_2.ico">
	<script type="text/javascript">
		function ALKISexport() {
			window.open(<?php echo "'alkisexport.php?gkz=".$gkz."&tabtyp=person&gmlid=".$gmlid."'"; ?>);
		}
	</script>
</head>
<body>
<?php
$erlaubnis = darf_ich(); if ($erlaubnis === 0) { die('<p class="stop1">Abbruch</p></body>'); }
$dbg=$debug;
if ($nodebug === "j") {$dbg=0;} 

echo "<p class='balken nakennz'>ALKIS Name id=".$gmlid."&nbsp;</p>\n"
."\n<h2><img src='ico/Eigentuemer.png' width='16' height='16' alt=''> Person</h2>";
$con = pg_connect($dbconn." options='--application_name=ALKIS-Auskunft_alkisnamstruk.php'");
if (!$con) "\n<p class='err'>Fehler beim Verbinden der DB</p>";

$sql="SELECT p.nachnameoderfirma, p.anrede, coalesce(p.vorname, '') AS vorn, coalesce(p.geburtsname, '') AS geburts, to_char(cast(p.geburtsdatum AS date),'DD.MM.YYYY') AS geburtsdatum, 
coalesce(p.namensbestandteil, '') AS nbest, coalesce(p.akademischergrad, '') AS aka, a.beschreibung AS anrv
FROM ax_person p LEFT JOIN ax_anrede_person a ON p.anrede = a.wert WHERE gml_id= $1 AND p.endet IS NULL;";

$v = array($gmlid);
$res = pg_prepare($con, "", $sql);
$res = pg_execute($con, "", $v);

if (!$res) {
	echo "\n<p class='err'>Fehler bei Zugriff auf Namensnummer</p>";
	if ($dbg > 2) {echo "\n<p class='dbg'>SQL=<br>".htmlentities(str_replace("$1", "'".$gmlid."'", $sql), ENT_QUOTES, "UTF-8")."</p>";}
}
if ($dbg > 0) {
	$zeianz=pg_num_rows($res);
	if ($zeianz > 1){
		echo "\n<p class='err'>Die Abfrage liefert mehr als eine Person!</p>";
		if ($dbg > 2) {echo "\n<p class='dbg'>SQL=<br>".htmlentities(str_replace("$1","'".$gmlid."'",$sql), ENT_QUOTES, "UTF-8")."</p>";}
	}
}
if ($row = pg_fetch_assoc($res)) {
	$vor=htmlentities($row["vorn"], ENT_QUOTES, "UTF-8");
	$nam=htmlentities($row["nachnameoderfirma"], ENT_QUOTES, "UTF-8");
	$geb=htmlentities($row["geburts"], ENT_QUOTES, "UTF-8");
	$anrk=$row["anrede"]; // Key
	$anr=$row["anrv"]; // Value
	$nbest=htmlentities($row["nbest"], ENT_QUOTES, "UTF-8");
	$aka=htmlentities($row["aka"], ENT_QUOTES, "UTF-8");

	echo "<table>"
		."\n\t<tr><td class='nhd'>Anrede:</td><td class='nam'>".DsKy($anrk, '* der Anrede-Kennung').$anr."</td></tr>"
		."\n\t<tr><td class='nhd'>Nachname oder Firma:</td><td class='nam'>".$nam."</td></tr>"
		."\n\t<tr><td class='nhd'>Vorname:</td><td class='nam'>".$vor."&nbsp;</td></tr>"
		."\n\t<tr><td class='nhd'>Geburtsname:</td><td class='nam'>".$geb."&nbsp;</td></tr>"
		."\n\t<tr><td class='nhd'>Geburtsdatum:</td><td class='nam'>".$row["geburtsdatum"]."&nbsp;</td></tr>"
		."\n\t<tr><td class='nhd'>Namensbestandteil:</td><td class='nam'>".$nbest."&nbsp;</td></tr>"
		."\n\t<tr><td class='nhd'>akademischer Grad:</td><td class='nam'>".$aka."&nbsp;</td></tr>"
	."\n</table>\n<hr>";

	// A d r e s s e
	if ($multiadress === "j") {$plural="n";} else {$plural="";}
	echo "\n\n<h3><img src='ico/Strasse_mit_Haus.png' width='16' height='16' alt=''> Adresse".$plural."</h3>";
	// Es können redundante Adressen vorhanden sein, z.B. aus Migration, temporär aus LBESAS.
	// Im Normalfall nur die "letzte" davon anzeigen. Auf Wunsch alle anzeigen, dazu den Anlass und das Datum um das zu bewerten.
	$sqla ="SELECT a.gml_id, w.value AS anltxt, a.anlass, to_char(cast(a.beginnt AS date),'DD.MM.YYYY') AS datum, a.ort_post, a.postleitzahlpostzustellung AS plz, a.strasse, a.hausnummer, a.bestimmungsland "
	."FROM ax_anschrift a JOIN ax_person p ON a.gml_id=ANY(p.hat) "
	."LEFT JOIN aa_anlassart w ON w.id = ANY(a.anlass) "
	."WHERE p.gml_id= $1 AND a.endet IS NULL AND p.endet IS NULL ORDER BY a.beginnt DESC ;";

	$v = array($gmlid);
	$resa = pg_prepare($con, "", $sqla);
	$resa = pg_execute($con, "", $v);
	if (!$resa) {
		echo "\n<p class='err'>Fehler bei Adressen</p>";
		if ($dbg > 2) {echo "\n<p class='err'>SQL=<br>". str_replace("$1", "'".$gmlid."'", $sqla)."</p>";}
	}

	$j=0;
	// Parameter $multiadress = j zeigt ALLE Adressen an
	while($rowa = pg_fetch_assoc($resa)) {
		$j++;
		if ($multiadress === "j" OR $j === 1) {
			$gmla=$rowa["gml_id"];
			$plz=$rowa["plz"];
			$ort=htmlentities($rowa["ort_post"], ENT_QUOTES, "UTF-8");
			$str=htmlentities($rowa["strasse"], ENT_QUOTES, "UTF-8");
			$hsnr=$rowa["hausnummer"];
			$land=htmlentities($rowa["bestimmungsland"], ENT_QUOTES, "UTF-8");
			$anlass=trim($rowa["anlass"], "{}"); // Key
			$anltxt=$rowa["anltxt"]; // Value
			$datum=$rowa["datum"];
			echo "<table>\n";
				if ($multiadress === "j" OR $showkey) {
					if ($dbg > 0) { // nur Entw.: Sortierung gml_id vs. beginnt?
						echo "\t<tr><td class='dbg'>gml_id:</td><td class='dbg'>".$rowa["gml_id"]."</td></tr>\n";
 					}
					echo "\t<tr><td class='nhd'>Datum:</td><td class='nam'>".$datum."</td></tr>\n"
					."\t<tr><td class='nhd'>Anlass:</td><td class='nam'>".DsKy($anlass, 'Anlass-*').$anltxt."</td></tr>\n";
				}
				echo "\t<tr><td class='nhd'>PLZ:</td><td class='nam'>".$plz."</td></tr>\n"
				."\t<tr><td class='nhd'>Ort:</td><td class='nam'>".$ort."</td></tr>\n"
				."\t<tr><td class='nhd'>Strasse:</td><td class='nam'>".$str."</td></tr>\n"
				."\t<tr><td class='nhd'>Hausnummer:</td><td class='nam'>".$hsnr."</td></tr>\n"
				."\t<tr><td class='nhd'>Land:</td><td class='nam'>".$land."</td></tr>\n"
			."\n</table>\n<br>";

			// Name und Adresse Kompakt (im Rahmen) - Alles was man für ein Anschreiben braucht
			echo "\n<img src='ico/Namen.png' width='16' height='16' alt='Brief' title='Anschrift'>"
			."\n<div class='adr' title='Anschrift'>\n\t".$anr." ".$aka." ".$vor." ".$nbest." ".$nam."<br>"
			."\n\t".$str." ".$hsnr."<br>"
			."\n\t".$plz." ".$ort."\n</div>";
		}
	}
	pg_free_result($resa);
	if ($j === 0) {
		echo "\n<p class='err'>Keine Adressen.</p>";
	} elseif ($j > 1) {
		echo "\n\t\t<p class='nwlink noprint'>"
		."\n\t\t\t<a href='".selbstverlinkung(). "?gkz=".$gkz."&amp;gmlid=".$gmlid.LnkStf();
		if ($multiadress === "j") {
			echo "&amp;multiadress=n' title='mehrfache Adressen unterdr&uuml;cken'>erste Adresse ";
		} else {
			echo "&amp;multiadress=j' title='Adressen ggf. mehrfach vorhanden'>alle Adressen ";
		}
		echo "\n\t\t\t</a>"
		."\n\t\t</p>";
	}

	// G R U N D B U C H
	echo "\n<hr>\n<h3><img src='ico/Grundbuch_zu.png' width='16' height='16' alt=''> Grundb&uuml;cher</h3>";
	// person <benennt< namensnummer >istBestandteilVon>                Buchungsblatt
	//                               >bestehtAusRechtsverhaeltnissenZu> namensnummer   (Nebenzweig/Sonderfälle?)

	$sqlg ="SELECT n.gml_id AS gml_n, n.laufendenummernachdin1421 AS lfd, n.zaehler, n.nenner, g.gml_id AS gml_g, g.bezirk, g.buchungsblattnummermitbuchstabenerweiterung as nr, g.blattart, wb.beschreibung AS blattartv, b.bezeichnung AS beznam "
	."FROM ax_person p JOIN ax_namensnummer n ON p.gml_id=n.benennt "
	."JOIN ax_buchungsblatt g ON g.gml_id=n.istbestandteilvon "
	."LEFT JOIN ax_buchungsblattbezirk b ON g.land=b.land AND g.bezirk=b.bezirk ".UnqKatAmt("g","b")
	."LEFT JOIN ax_blattart_buchungsblatt wb ON g.blattart = wb.wert "
	."WHERE p.gml_id= $1 AND p.endet IS NULL AND n.endet IS NULL AND b.endet IS NULL "
	."ORDER BY b.bezeichnung, g.buchungsblattnummermitbuchstabenerweiterung, n.laufendenummernachdin1421;";

	$v = array($gmlid);
	$resg = pg_prepare($con, "", $sqlg);
	$resg = pg_execute($con, "", $v);

	if (!$resg) {
		echo "\n<p class='err'>Fehler bei Grundbuch</p>";
		if ($dbg > 2) {
			echo "\n<p class='err'>SQL=<br>".htmlentities(str_replace("$1", "'".$gmlid."'", $sqlg ), ENT_QUOTES, "UTF-8")."</p>";
		}
	}
	echo "<table class='eig'>"
	."\n<tr>"
		."\n\t<td class='heads'>Bezirk<img src='ico/sort.png' width='10' height='10' alt='' title='Sortierung'></td>"
		."\n\t<td class='head'>Blattart</td>"
		."\n\t<td class='heads'>Blatt<img src='ico/sort.png' width='10' height='10' alt='' title='Sortierung'></td>"
		."\n\t<td class='heads'>Namensnummer<img src='ico/sort.png' width='10' height='10' alt='' title='Sortierung'></td>"
		."\n\t<td class='head'>Anteil</td>"
		."\n\t<td class='head nwlink noprint' title='Verlinkung zu anderen Nachweis-Arten und verbundenen Objekten'>weitere Auskunft</td>"
	."\n</tr>";

	$i=0;
	$zpaar=false;
	$gwbeznam='';
	while($rowg = pg_fetch_assoc($resg)) {
		$gmln=$rowg["gml_n"];
		$gmlg=$rowg["gml_g"];
		$namnum=kurz_namnr($rowg["lfd"]);
		$zae=$rowg["zaehler"];
		$blattkey=$rowg["blattart"]; // Key
		$blattart=$rowg["blattartv"]; // Value
		if ($zpaar) {$trclass='paa';} else {$trclass='unp';}
		$beznam=$rowg["beznam"];
		$zpaar=!$zpaar;
		echo "\n<tr class='".$trclass."'>"
			."\n\t<td>".DsKy($rowg["bezirk"], 'Grundbuch-Bezirks-*');
			if ($gwbeznam != $beznam){
				echo "<b>".$beznam."</b>";
				$gwbeznam=$beznam;
			} else {
				echo $beznam;
			}
			echo "</td>";

			echo "\n\t<td>".DsKy($blattkey, 'Blattart-*').$blattart."</td>";
			echo "\n\t<td><span class='wichtig'>".$rowg["nr"]."</span></td>";// Blatt

			echo "\n\t<td>"; // Namensnummer
			if ($namnum == "") {
				echo "&nbsp;";
			} else {
				echo $namnum;
			}
			echo "</td>";

			echo "\n\t<td>"; // Anteil
			if ($zae == '') {
				echo "&nbsp;";
			} else {
				echo $zae."/".$rowg["nenner"]." Anteil";
			} 
			echo "</td>"
			."\n\t<td>"
			."\n\t\t<p class='nwlink noprint'>"
				."\n\t\t\t<a href='alkisbestnw.php?gkz=".$gkz."&amp;gmlid=".$gmlg.LnkStf()."' title='Bestandsnachweis'>".$blattart
				."\n\t\t\t<img src='ico/GBBlatt_link.png' width='16' height='16' alt=''></a>"
			."\n\t\t</p>"
			."\n\t</td>"
		."\n</tr>";
		// +++ >bestehtAusRechtsverhaeltnissenZu> namensnummer ?
		// z.B. eine Namennummer "Erbengemeinschaft" zeigt auf Namensnummern mit Eigentümern
		$i++;
	}
	pg_free_result($resg);
	echo "</table>";
	if ($i === 0) {echo "\n<p class='err'>Kein Grundbuch.</p>";}
} else {
	echo "\n\t<p class='err'>Fehler! Kein Treffer f&uuml;r Person".$gmlid."</p>\n";
}
pg_free_result($res);

echo "\n<div class='buttonbereich noprint'>\n<hr>"
	."\n\t<a title='zur&uuml;ck' href='javascript:history.back()'><img src='ico/zurueck.png' width='16' height='16' alt='zur&uuml;ck'></a>&nbsp;";
if ($PrntBtn==true){echo "\n\t<a title='Drucken' href='javascript:window.print()'><img src='ico/print.png' width='16' height='16' alt='Drucken'></a>&nbsp;";}
echo "\n\t<a title='Export als CSV' href='javascript:ALKISexport()'><img src='ico/download.png' width='16' height='16' alt='Export'></a>&nbsp;"
."\n</div>";

footer($gmlid, selbstverlinkung()."?", "");
?>

</body>
</html>
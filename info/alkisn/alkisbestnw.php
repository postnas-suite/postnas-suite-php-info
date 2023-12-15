<?php
/*	alkisbestnw.php

	ALKIS-Auskunft
	Author: Frank Jäger, Kommunales Rechenzentrum Minden-Ravensberg/Lippe (Lemgo)

	Bestandsnachweis für ein Grundbuch (-Blatt) aus ALKIS PostNAS

	Version:
	2016-02-24 Version für norGIS-ALKIS-Import
	....
	2020-02-20 Authentifizierung ausgelagert in Function darf_ich()
	2020-12-03 function selbstverlinkung() statt $_SERVER['PHP_SELF']
	2020-12-15 Input-Validation und Strict Comparisation (===)
	2021-12-09 Neue Parameter: $katAmtMix (Kataster-Amt-Mix), $PrntBtn (Drucken-Schaltfläche)
			   Tabellenstruktur verbessert und Title bei "Recht an".
	2021-12-30 Bestandsnachweis recursiv über alle Buchungs-Ebenen
	2022-01-13 Functions aus alkisfkt.php in dies Modul verschoben, wenn nur hier verwendet. Neue Functions LnkStf(), DsKy()
	2022-07-05 PHP 8.1: Connection verwenden bei "pg_prepare" und "pg_execute", keine NULL-Werte in String-Functions verwenden
*/

function bnw_bsdaten($gml_h, $ebene) {
/*	Bestandsnachweis - Buchungsstellen-Daten
	"dienende" Buchungsstellen suchen. Miteigentumsanteil, Erbbaurecht usw. 
	Return: gml_id der dienenden Buchungsstelle wenn gefunden? Sonst Leerstring */
	global $dbg, $bartkey, $bart, $bartstory, $anteil, $con;

//	dann "dienende" Buchungsstellen
//  sh=herrschend          sd=dienend
//  ax_buchungsstelle >zu> ax_buchungsstelle (des gleichen Blattes) 
//  ax_buchungsstelle >an> ax_buchungsstelle (anderes Blatt, z.B Erbbaurecht an)
//	- "zu" kommt in der Praxis (NRW) nicht vor, wird hier nicht berücksichtigt

	if ($ebene > 5) {echo "\n<p class='err'>Ungewöhnlich tiefe Schachtelung ".$ebene." der Buchungs-Stellen.</p>";}

	$sql ="SELECT sd.gml_id, sd.buchungsart, sd.zaehler, sd.nenner, sd.laufendenummer AS lfd, sd.beschreibungdesumfangsderbuchung AS udb, "
	."sd.nummerimaufteilungsplan AS nrap, sd.beschreibungdessondereigentums AS sond, "
	."wb.beschreibung AS bart, wb.dokumentation, "
	."b.gml_id as gbgml, b.bezirk, b.buchungsblattnummermitbuchstabenerweiterung AS blatt, b.blattart, "
	."bb.beschreibung AS blattartv, z.bezeichnung AS beznam "
	."FROM ax_buchungsstelle sh "
	."JOIN ax_buchungsstelle sd ON sd.gml_id=ANY(sh.an) "
	."JOIN ax_buchungsblatt b ON b.gml_id=sd.istbestandteilvon "	
	."LEFT JOIN ax_buchungsart_buchungsstelle wb ON sd.buchungsart = wb.wert "
	."LEFT JOIN ax_buchungsblattbezirk z ON b.land=z.land AND b.bezirk=z.bezirk ".UnqKatAmt("b","z")
	."LEFT JOIN ax_blattart_buchungsblatt bb ON b.blattart = bb.wert "
	."WHERE sh.gml_id= $1 AND sh.endet IS NULL AND sd.endet IS NULL AND b.endet IS NULL AND z.endet IS NULL "
	."ORDER BY sd.laufendenummer;";
	$v=array($gml_h); // gml_id "herrschende" B-Stelle
	$resan=pg_prepare($con, "", $sql);
	$resan=pg_execute($con, "", $v);
	if (!$resan) {
		echo "\n<p class='err'>Fehler bei 'dienende Buchungsstelle'.</p>";
		if ($dbg > 2) {echo "\n<p class='dbg'>SQL=<br>".htmlentities(str_replace("$1", "'".$gml_bs."'", $sql), ENT_QUOTES, "UTF-8")."</p>";}
	}
	$zeianz=pg_num_rows($resan); // Zeilen-Anzahl = Returnwert
	$und = false; // mehrfaches "Recht an" auf gleicher Ebene
	while($row= pg_fetch_assoc($resan)) {
		$gml_bsan=$row["gml_id"];	// id der dien. BS
		$blatt=ltrim($row["blatt"], "0");		

		// als Global-Var zur Sub-Function:
		$bartkey=$row["buchungsart"];	
		$bart=$row["bart"]; // Buchungsart, entschlüsselt
		$bartstory=htmlentities($row["dokumentation"], ENT_QUOTES, "UTF-8");
		if ($row["zaehler"] == "") {$anteil = "";} 
		else {$anteil = $row["zaehler"]."/".$row["nenner"];}

		// Zeile ausgeben Buchungsstelle dienend 
		bnw_bszeile_d($row["bezirk"], $row["beznam"], $blatt, $row["blattart"], $row["blattartv"], $row["lfd"], $row["gbgml"], $gml_bsan, $ebene, $und);
		$und = true;
		if ($row["nrap"] != "") { // Nr im Auft.Plan
			echo "\n<tr>\n\t<td colspan=3></td><td class='nrap' colspan=4>Nummer <span class='wichtig'>".$row["nrap"]."</span> im Aufteilungsplan.</td><td></td>\n</tr>";
		}
		if ($row["sond"] != "") { // Sondereigentumsbeschreibung
			echo "\n<tr>\n\t<td></td><td class='sond' colspan=6 title='Sondereigentums-Beschreibung'>Verbunden mit dem Sondereigentum an: ".$row["sond"]."</td><td></td>\n</tr>";
		}

		// Rekursiver Aufruf der gleichen Function, weitere Ebene dienend?
		$tiefer = bnw_bsdaten($gml_bsan, ($ebene + 1));
		If ($tiefer == 0) {  // Wenn nicht, dann kann es Flurstücke dazu geben
			$fscnt= bnw_fsdaten($gml_bsan, false); // Flurstücksdaten
		}
	}
	pg_free_result($resan);
	return $zeianz;
}

function bnw_fsdaten($gml_bs, $mit_buchung_link) {
/*	Bestandsnachweis - Flurstücksdaten
	Die Tabellenzeilen mit den Flurstücksdaten zu EINER Buchungsstelle im Bestandsnachweis ausgeben.
	Die Funktion wird entweder aufgerufen für die Buchungen direkt auf dem GB (Normalfall)
	oder bei Erbbaurecht für die mit "an" verknüpften Buchungsstellen der untersten Ebene (dienende Buchung).
	Der Tabellenkopf wird im aufrufenden Programm ausgegeben. 
	Return: Anzahl der ausgegebenen Flurstücke */
	global $dbg, $gkz, $showkey, $filtkreis, $filtgem, $trclass, $katAmtMix, $lfdnr, $altlfdnr, $bartkey, $bart, $bartstory, $anteil, $con;

	$sql="SELECT g.gemarkungsnummer, g.bezeichnung, f.gml_id, f.flurnummer, f.zaehler, f.nenner, f.amtlicheflaeche "
	."FROM ax_flurstueck f LEFT JOIN ax_gemarkung g ON f.land=g.land AND f.gemarkungsnummer=g.gemarkungsnummer ".UnqKatAmt("f","g")
	."WHERE f.endet IS NULL AND g.endet IS NULL AND f.istgebucht = $1 ";
	if ($filtgem === '') { // ungefiltert
		$v=array($gml_bs);
	} else {
		$sql.="AND f.gemeindezugehoerigkeit_kreis = $2 AND f.gemeindezugehoerigkeit_gemeinde = $3 "; // Zuständiges Gebiet
		$v=array($gml_bs, $filtkreis, $filtgem);
	}
	$sql.="ORDER BY f.gemarkungsnummer, f.flurnummer, f.zaehler, f.nenner;";
	$resf = pg_prepare($con, "", $sql);
	$resf = pg_execute($con, "", $v);
	if (!$resf) {echo "\n<p class='err'>Fehler bei Flurst&uuml;ck</p>";}
	$zeianz=pg_num_rows($resf);
	while($rowf = pg_fetch_assoc($resf)) {
		$fskenn=$rowf["zaehler"];
		if ($rowf["nenner"] != "") { // Bruch
			$fskenn.="/".$rowf["nenner"];
		}
		$flae=number_format($rowf["amtlicheflaeche"],0,",",".") . " m&#178;";

		echo "\n<tr class='".$trclass."'>"; // eine Zeile je Flurstueck
		
		// Sp. 1-3 der Tab. Daten aus Buchungsstelle, nicht aus FS
		if($lfdnr === $altlfdnr) { // gleiches Grundstück, leer lassen
			echo "\n\t<td>&nbsp;</td>"
			."\n\t<td>&nbsp;</td>"
			."\n\t<td>&nbsp;</td>";
		} else { // Sprungmarke, BVNR
			$bvnr=str_pad($lfdnr, 4, "0", STR_PAD_LEFT);
			echo "\n\t<td id='bvnr".$bvnr."'><span class='wichtig'>".$bvnr."</span>\n\t</td>"
			."\n\t<td title ='".$bartstory."'>".DsKy($bartkey, 'Buchungsart-*').$bart."</td>"
			."\n\t<td>&nbsp;</td>";
			$altlfdnr=$lfdnr;
		}

		// Sp. 4-7 aus Flurstück
		echo "\n\t<td>".DsKy($rowf["gemarkungsnummer"], 'Gemarkungsnummer').$rowf["bezeichnung"]."</td>"
		."\n\t<td>".$rowf["flurnummer"]."</td>\n\t<td class='fsnr'><span class='wichtig'>".$fskenn."</span></td>"
		."\n\t<td class='fla'>".$flae."</td>"
		."\n\t<td>\n\t\t<p class='nwlink noprint'>";

		// Buchung BVNR
		If ($mit_buchung_link == true) { // nur bei Grundstück
			echo "\n\t\t\t<a href='alkisgsnw.php?gkz=".$gkz."&amp;gmlid=".$gml_bs.LnkStf()
			."' title='Grundst&uuml;cksnachweis'>Buchung <img src='ico/Grundstueck_Link.png' width='16' height='16' alt=''></a>&nbsp;";			
			$mit_buchung_link = false; // nur in erster Zeile
		}
		// Flurstk.
		echo "\n\t\t\t<a href='alkisfsnw.php?gkz=".$gkz."&amp;gmlid=".$rowf["gml_id"]."&amp;eig=n".LnkStf()."' title='Flurst&uuml;cksnachweis'>Flurst&uuml;ck "
		."<img src='ico/Flurstueck_Link.png' width='16' height='16' alt=''></a>"
		."\n\t\t</p>\n\t</td>\n</tr>";
	}
	pg_free_result($resf);
	return $zeianz;
}

function bnw_bszeile_h() {
/*	Bestandsnachweis - Buchungs-Stellen-Zeile ausgeben - herrschend.
	Die GB-Daten hierzu stehen bereits im Kopf und bleiben in der Tab. leer */
	global $dbg, $gkz, $trclass, $lfdnr, $bartkey, $bart, $bartstory, $anteil;

	$bvnr=str_pad($lfdnr, 4, "0", STR_PAD_LEFT);
	echo "\n<tr class='".$trclass."'>"
	."\n\t<td id='bvnr".$bvnr."'><span class='wichtig'>".$bvnr."</span></td>" // Sprungmarke=BVNR auf dem GB-Blatt
	."\n\t<td class='dien' title='".$bartstory."'>".DsKy($bartkey, 'Buchungsart-*').$bart."</td>"
	."\n\t<td class='dien'>".$anteil."</td>"
	."\n\t<td colspan=5>&nbsp;</td>\n</tr>";
}

function bnw_bszeile_d($bezkey, $beznam, $blatt, $blattartkey, $blattart, $lfdnran, $gbgml, $gml_bsan, $ebene, $und) {
/*	Bestandsnachweis - Buchungsstellen-Zeile ausgeben - dienend
	Eine Folge-Zeile für eine dienende Buchung ausgeben. */
	global $gkz, $trclass, $bartkey, $bart, $bartstory, $anteil;

	$bvnr=str_pad($lfdnran, 4, "0", STR_PAD_LEFT);
	$filler=str_repeat("&nbsp;", $ebene - 2); // 3 und 4 Einrücken
	if ($und){$filler.="und ";}; // Unterscheidung Hierarchie (Ebene wechselt) von Liste (gleiche Ebene)
	echo "\n<tr class='".$trclass."'>"
	."\n\t<td title='Ebene ".$ebene."'>".$filler."an</td>"
	."\n\t<td class='dien' title='".$bartstory."'>".DsKy($bartkey, 'Buchungsart-*').$bart."</td>"
	."\n\t<td class='dien'>".$anteil."</td>";

	// Sp.4 GB-Bezirk
	echo "\n\t<td class='dien' title='Grundbuch-Bezirk'>".DsKy($bezkey, 'Grundbuch-Bezirk-*').htmlentities($beznam, ENT_QUOTES, "UTF-8")."</td>"
	."\n\t<td class='dien' title='".$blattart."'>".$blatt."</td>"
	."\n\t<td class='dien' title='Bestandsverzeichnis-Nummer'>".$bvnr."</td>"
	."\n\t<td class='dien'></td>"; 

	echo "\n\t<td>\n\t\t<p class='nwlink noprint'>";

	// Link Bestand Blatt
	echo "\n\t\t\t".DsKy($blattartkey, 'Blatt-Art-*')."<a href='alkisbestnw.php?gkz=".$gkz."&amp;gmlid=".$gbgml.LnkStf()
	."#bvnr".$lfdnran."' title='Zum Grundbuchnachweis des dienenden Blattes'>".$blattart
	." <img src='ico/GBBlatt_link.png' width='16' height='16' alt=''></a>";

	if ($bartkey < 2000){
		// Link Buchung BVNR nur für Grundstück usw.
		echo "<br>\n\t\t\t<a href='alkisgsnw.php?gkz=".$gkz."&amp;gmlid=".$gml_bsan.LnkStf()
		."' title='Grundst&uuml;cksnachweis'>Buchung <img src='ico/Grundstueck_Link.png' width='16' height='16' alt=''></a>";	
	}
	echo "\n\t\t</p>"
	."\n\t</td>\n</tr>";
}

// Start
ini_set("session.cookie_httponly", 1);
session_start();
$showkey="n"; $nodebug=""; // Var. initalisieren
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
	<title>ALKIS Bestandsnachweis</title>
	<link rel="stylesheet" type="text/css" href="alkisauszug.css">
	<link rel="shortcut icon" type="image/x-icon" href="ico/Grundbuch.ico">
	<script type="text/javascript">
		function ALKISexport(gmlid) {
			window.open('alkisexport.php?gkz=<?php echo $gkz;?>&tabtyp=grundbuch&gmlid=' + gmlid);
		}
	</script>
</head>
<body>
<?php

$erlaubnis = darf_ich(); if ($erlaubnis === 0) { die('<p class="stop1">Abbruch</p></body>'); }
$dbg=$debug;
if ($nodebug === "j") {$dbg=0;} 

$con = pg_connect($dbconn." options='--application_name=ALKIS-Auskunft_alkisbestnw.php'");
if (!$con) echo "\n<p class='err'>Fehler beim Verbinden der DB</p>";

// G R U N D B U C H
$sql="SELECT g.gml_id, g.bezirk, g.buchungsblattnummermitbuchstabenerweiterung AS blatt, g.blattart, wb.beschreibung AS blattartv, wb.dokumentation AS blattartd, 
b.gml_id, b.bezirk, b.bezeichnung AS beznam, d.gml_id, d.land, d.bezeichnung, d.stelle, d.stellenart, wd.beschreibung AS stellev 
FROM ax_buchungsblatt g 
LEFT JOIN ax_buchungsblattbezirk b ON g.land=b.land AND g.bezirk=b.bezirk ".UnqKatAmt("g","b")
."LEFT JOIN ax_dienststelle d ON b.land=d.land AND b.gehoertzu_stelle=d.stelle ".UnqKatAmt("b","d")
."LEFT JOIN ax_blattart_buchungsblatt wb ON g.blattart = wb.wert
LEFT JOIN ax_behoerde wd ON d.stellenart = wd.wert 
WHERE g.gml_id= $1 AND g.endet IS NULL AND b.endet IS NULL AND d.endet IS NULL;";
// .. AND d.stellenart=1000 

$v = array($gmlid);
$res = pg_prepare($con, "", $sql);
$res = pg_execute($con, "", $v);
if (!$res) {
	echo "\n<p class='err'>Fehler bei Grundbuchdaten.</p>";
	if ($dbg > 2) {echo "\n<p class='dbg'>SQL=<br>".htmlentities(str_replace("$1", "'".$gmlid."'", $sql), ENT_QUOTES, "UTF-8")."</p>";}
}
$zeianz=pg_num_rows($res);
if ($dbg > 0) {
	if ($zeianz > 1){
		echo "\n<p class='err'>Die Abfrage liefert mehr als ein (".$zeianz.") Grundbuch-Objekt!</p>";
		if ($dbg > 2) {echo "\n<p class='dbg'>SQL=<br>".htmlentities(str_replace("$1","'".$gmlid."'",$sql), ENT_QUOTES, "UTF-8")."</p>";}
	}
}
if ($zeianz == 0){
	echo "\n<p class='err'>Fehler! Kein Treffer f&uuml;r ein Grundbuch-Blatt mit gml_id=".$gmlid."</p>";
	if ($dbg > 2) {echo "\n<p class='dbg'>SQL=<br>".htmlentities(str_replace("$1","'".$gmlid."'",$sql), ENT_QUOTES, "UTF-8")."</p>";}
	echo "\n</body>\n</html>";
	return;
}
if ($row = pg_fetch_assoc($res)) {
	$blattkey=$row["blattart"];
	$blattart=$row["blattartv"];
	$blatt=ltrim($row["blatt"], "0");

	echo "<p class='balken gbkennz'>ALKIS Bestand ".$row["bezirk"]." - ".$blatt."&nbsp;</p>"; // Balken
	echo "\n<h2>Grundbuch</h2>";
	echo "\n<table class='outer'>" // Blatt UND Eigent.
		."\n\t<tr>\n\t\t<td class='ll'><img src='ico/Grundbuch.png' width='16' height='16' alt=''> Blatt:</td>"
		."\n\t\t<td colspan='2'>"; // Outer Mitte, Kennz. im Rahmen
			if ($blattkey === "1000") {
				echo "\n\t\t\t<table class='kennzgb' title='Bestandskennzeichen'>";
			} else {
				echo "\n\t\t\t<table class='kennzgbf' title='Bestandskennzeichen'>"; // dotted
			}
			echo "\n\t\t\t<tr>"
				."\n\t\t\t\t<td class='head'>".DsKy($row["stellenart"], 'Stellenart-*').$row["stellev"]."</td>"
				."\n\t\t\t\t<td class='head'>Bezirk</td>"
				."\n\t\t\t\t<td class='head' title='".htmlentities($row["blattartd"], ENT_QUOTES, "UTF-8")."'>".DsKy($blattkey, 'Blattart-*').$blattart."</td>"
			."\n\t\t\t</tr>\n\t\t\t<tr>"
				."\n\t\t\t\t<td title='Amtsgerichtsbezirk'>".DsKy($row["stelle"], 'Stelle-*').htmlentities($row["bezeichnung"], ENT_QUOTES, "UTF-8")."</td>"
				."\n\t\t\t\t<td title='Grundbuchbezirk'>".DsKy($row["bezirk"], 'Grundbuchbezirk-*').htmlentities($row["beznam"], ENT_QUOTES, "UTF-8")."</td>"
				."\n\t\t\t\t<td title='Grundbuch-Blatt'><span class='wichtig'>".$blatt."</span></td>"
			."\n\t\t\t</tr>"
		."\n\t\t\t</table>"
		."\n\t\t</td>\n\t\t<td>&nbsp;</td>\n\t</tr>";
}
pg_free_result($res);

if ($blattkey === "5000") { // fikt. Blatt
	echo "\n<p>Keine Angaben zum Eigentum bei fiktivem Blatt.</p>";
} else { // E I G E N T Ü M E R
	$n = eigentuemer($gmlid, true, false); // MIT Adressen.
	if ($n === 0) { // keine NamensNr, kein Eigentuemer
		echo "\n<p class='err'>Keine Namensnummer gefunden.</p>"
		."\n<p>Bezirk: ".$row["bezirk"].", Blatt: ".$blatt.", Blattart ".$blattkey." (".$blattart.")</p>";
	}
}
echo "\n</table>";

// Vorab eine Tiefbohrung zur Sondierung von (potentiell) herrschend bis dienend über max. 4 Buchungs-Stellen.
// Diese Zählung sagt nur aus, ob es "generell" solche Fälle auf diesem Grundbuch gibt (ist selten).
// In jedem einzelnen Zweig der Buchungen muss aber individuell danach gesucht werden. 
// Die Relation "zu" ist hier einbezogen, wird aber später nicht ausgewertet. Hier könnte man die Differenz erkennen falls "zu" doch mal auftaucht.
$sql ="SELECT count(s2.laufendenummer) AS anz2";
if ($dbg > 1) {$sql.=", count(s3.laufendenummer) AS anz3, count(s4.laufendenummer) AS anz4";}
$sql.=" FROM ax_buchungsstelle sh " // herrschend
	."LEFT JOIN ax_buchungsstelle s2 ON (s2.gml_id=ANY(sh.an) OR s2.gml_id=ANY(sh.zu)) ";
if ($dbg > 1) {
	$sql.="LEFT JOIN ax_buchungsstelle s3 ON (s3.gml_id=ANY(s2.an) OR s3.gml_id=ANY(s2.zu)) "
		."LEFT JOIN ax_buchungsstelle s4 ON (s4.gml_id=ANY(s3.an) OR s4.gml_id=ANY(s3.zu)) ";
}
$sql.="WHERE sh.istbestandteilvon= $1 AND sh.endet IS NULL AND s2.endet IS NULL ";
if ($dbg > 1) {$sql.="AND s3.endet IS NULL AND s4.endet IS NULL";}
$v=array($gmlid); // GB-Blatt
$res=pg_prepare($con, "", $sql);
$res=pg_execute($con, "", $v);
if (!$res) {
	echo "\n<p class='err'>Fehler bei tiefer Suche nach Buchungen.</p>";
	if ($dbg > 2) {echo "\n<p class='dbg'>SQL=<br>".htmlentities(str_replace("$1", "'".$gmlid."'", $sql), ENT_QUOTES, "UTF-8")."</p>";}
}
$row=pg_fetch_assoc($res);
$anz2=$row["anz2"]; // steuert Tabellenform und Überschrift
if ($dbg > 1) {
	$anz3=$row["anz3"];
	$anz4=$row["anz4"];
	echo "\n<p class='dbg'>Anzahl dienende Buchungen Ebene 2: '".$anz2."', Ebene 3: '".$anz3."', Ebene 4: '".$anz4."'</p>";
}
echo "\n<hr>\n\n<h3><img src='ico/Flurstueck.png' width='16' height='16' alt=''>";
if ($anz2 > 0) { // auch "Rechte an", also dienende B.
	echo " Rechte und Flurst&uuml;cke</h3>\n<table class='fs'>";
	echo "\n<tr>" // zus. Kopfz. "Rechte" vor FS
		."\n\t<td class='heads' title='laufende Nummer Bestandsverzeichnis (BVNR) = Grundst&uuml;ck'>\n\t\t<span class='wichtig'>BVNR</span>"
			."\n\t\t<img src='ico/sort.png' width='10' height='10' alt='' title='prim&auml;re Sortierung'>\n\t</td>"
		."\n\t<td class='head dien' title='herrschendes Grundst&uuml;ck'>Buchungsart</td>"
		."\n\t<td class='head dien'>Anteil</td>"
		."\n\t<td class='head dien'>Bezirk</td>"
		."\n\t<td class='head dien'>Blatt</td>"
		."\n\t<td class='head dien'>BVNR</td>"
		."\n\t<td class='head dien'>&nbsp;</td>"
		."\n\t<td class='head dien'>&nbsp;</td>"
	."\n</tr>";
} else { // keine Rechte an, nur FS
	echo " Flurst&uuml;cke</h3>\n<table class='fs'>";
}
echo "\n<tr>"; // Kopfzeile "Flurstück"
	if ($anz2 > 0) { // BS und FS
		echo "\n\t<td class='head'>&nbsp;</td>"
		."\n\t<td class='head'>&nbsp;</td>";
	} else { // nur FS
		echo "\n\t<td class='heads' title='laufende Nummer Bestandsverzeichnis (BVNR) = Grundst&uuml;ck'>\n\t\t<span class='wichtig'>BVNR</span>"
			."\n\t\t<img src='ico/sort.png' width='10' height='10' alt='' title='prim&auml;re Sortierung'>"
		."\n\t</td>"
		."\n\t<td class='head'>Buchungsart</td>";
	}
	echo "\n\t<td class='head'>&nbsp;</td>"
	."\n\t<td class='heads'>Gemarkung</td>"
	."\n\t<td class='heads'>Flur</td>"
	."\n\t<td class='heads fsnr' title='Flurst&uuml;cksnummer (Z&auml;hler / Nenner)'><span class='wichtig'>Flurst.</span></td>"
	."\n\t<td class='head fla'>Fl&auml;che</td>"
	."\n\t<td class='head nwlink noprint' title='Verlinkung zu anderen Nachweis-Arten und verbundenen Objekten'>weitere Auskunft</td>"
."\n</tr>";

// Blatt ->  B u c h u n g s s t e l l e (oberste Ebene 1, Grundstück oder herrschend). Relation istBestandteilVon
// aktuelles ax_buchungsblatt <istBestandteilVon< ax_buchungsstelle 
$sql ="SELECT s.gml_id, s.buchungsart, s.laufendenummer AS lfd, s.beschreibungdesumfangsderbuchung AS udb, s.zaehler, s.nenner, 
s.nummerimaufteilungsplan AS nrap, s.beschreibungdessondereigentums AS sond, b.beschreibung as bart, b.dokumentation
FROM ax_buchungsstelle s 
LEFT JOIN ax_buchungsart_buchungsstelle b ON s.buchungsart = b.wert
WHERE s.istbestandteilvon= $1 AND s.endet IS NULL ORDER BY cast(s.laufendenummer AS integer);";
$v=array($gmlid);
$res=pg_prepare($con, "", $sql);
$res=pg_execute($con, "", $v);
if (!$res) {
	echo "\n<p class='err'>Fehler bei Buchung.</p>";
	if ($dbg > 2) {echo "\n<p class='dbg'>SQL=<br>".htmlentities(str_replace("$1", "'".$gmlid."'", $sql), ENT_QUOTES, "UTF-8")."</p>";}
}
$i=0;  // Zähler Buchungs-Stellen auf oberer Ebene
$zpaar=false;
$altlfdnr=''; // GW

while($row = pg_fetch_assoc($res)) { // Loop Buchungsstellen der 1. Ebene (herrschend oder nur FS)
	$i++;
	$gml_bs=$row["gml_id"]; // gml der Buchungsstelle
	$lfdnr=$row["lfd"];
	$bartkey=$row["buchungsart"]; // Key
	$bart=$row["bart"]; // BuchungsART Text dazu
	$bartstory=htmlentities($row["dokumentation"], ENT_QUOTES, "UTF-8"); // .. für Anzeige aufbereitet	
	if ($row["zaehler"] == "") {$anteil = "";} 
	else {$anteil = $row["zaehler"]."/".$row["nenner"];}
	if ($zpaar) {$trclass='paa';} else {$trclass='unp';} // Farbwechsel je Buchung auf Ebene 1
	$zpaar=!$zpaar;

	if ($bartkey <= 1102) { // (aufgeteiltes) Grundstück

		$zeianz = bnw_fsdaten($gml_bs, true); // Flurstücksdaten zur direkten Buchungsstelle
		if ($zeianz === 0) {
			echo "\n<tr class='".$trclass."'>\n\t<td><span class='wichtig'>".$lfdnr."</span></td>"
			."\n\t<td colspan='7'><p class='warn'>Keine Flurst&uuml;cke im berechtigten Bereich.</p></td>\n\t<td></td>\n</tr>";
		}

	} else { // herrschende Buchung

		bnw_bszeile_h(); // Die herrschende Buchung, aus Global

		$altlfdnr=$lfdnr;

		if ($row["nrap"] != "") { // Nr im Auft.Plan
			echo "\n<tr>\n\t<td colspan=3></td><td class='nrap' colspan=4>Nummer <span class='wichtig'>".$row["nrap"]."</span> im Aufteilungsplan.</td><td></td>\n</tr>";
		}

		if ($row["sond"] != "") { // Sondereigentumsbeschreibung
			echo "\n<tr>\n\t<td></td><td class='sond' colspan=6 title='Sondereigentums-Beschreibung'>Verbunden mit dem Sondereigentum an: ".$row["sond"]."</td><td></td>\n</tr>";
		}

		$tiefer = bnw_bsdaten($gml_bs, 2);  // dienend, recursiv Ebenen 2,3,4

	}
}
echo "\n</table>";
if ($i === 0) {echo "\n<p class='err'>Keine Buchung gefunden.</p>";}
pg_free_result($res);

// B e r e c h t i g t e  Buchungsblätter mit Recht an dem aktuellen (fiktiven?) Blatt

// bf                          sf            sb                               bb
// Blatt   <istBestandteilVon< Stelle  <an<  Stelle      >istBestandteilVon>  Blatt
// Fiktiv                      Fiktiv  <zu<  Berechtigt                       Berechtigt
$sql="SELECT sf.laufendenummer AS anlfdnr, bb.gml_id, bb.land, bb.bezirk, bb.buchungsblattnummermitbuchstabenerweiterung AS blatt, bb.blattart, wa.beschreibung AS blattartv, 
sb.gml_id AS gml_s, sb.laufendenummer AS lfdnr, sb.buchungsart, wb.beschreibung AS bart, wb.dokumentation AS bartd, bz.bezeichnung AS beznam, d.bezeichnung, d.stelle, d.stellenart, wd.beschreibung AS stellev 
FROM ax_buchungsstelle sf JOIN ax_buchungsstelle sb ON (sf.gml_id=ANY(sb.an) OR sf.gml_id=ANY(sb.zu)) 
JOIN ax_buchungsblatt bb ON bb.gml_id=sb.istbestandteilvon 
LEFT JOIN ax_buchungsblattbezirk bz ON bb.land=bz.land AND bb.bezirk=bz.bezirk ".UnqKatAmt("bb","bz")
."LEFT JOIN ax_dienststelle d ON bz.land=d.land AND bz.gehoertzu_stelle=d.stelle ".UnqKatAmt("bz","d")
."LEFT JOIN ax_blattart_buchungsblatt wa ON bb.blattart = wa.wert
LEFT JOIN ax_buchungsart_buchungsstelle wb ON sb.buchungsart = wb.wert
LEFT JOIN ax_behoerde wd ON d.stellenart = wd.wert
WHERE sf.istbestandteilvon = $1 AND sf.endet IS NULL AND sb.endet IS NULL AND bb.endet IS NULL AND bz.endet IS NULL AND d.endet IS NULL 
ORDER BY cast(sf.laufendenummer AS integer), bz.bezeichnung, bb.buchungsblattnummermitbuchstabenerweiterung, cast(sb.laufendenummer AS integer);";

$v = array($gmlid);
$resb = pg_prepare($con, "", $sql);
$resb = pg_execute($con, "", $v);
if (!$resb) {
	echo "\n<p class='err'>Fehler bei 'Berechtigte Bl&auml;tter.</p>";
	if ($dbg > 2) {echo "\n<p class='dbg'>SQL=<br>".htmlentities(str_replace("$1", "'".$gmlid."'", $sql), ENT_QUOTES, "UTF-8")."</p>";}
}
$b=0; // count: Buchungen / Blätter
$zpaar=false;
while($rowb = pg_fetch_assoc($resb)) {
	if ($b === 0) { // Überschrift und Tabelle nur ausgeben, wenn etwas gefunden wurde
		echo "\n\n<h3><img src='ico/Grundbuch_zu.png' width='16' height='16' alt=''> Berechtigte Grundb&uuml;cher</h3>"
		."\n<table class='outer'>\n<tr>"
		."\n\t<td class='heads' title='lfd. Nr. auf diesem Blatt, wie im Teil Flurst&uuml;cke'><span class='wichtig'>an BVNR</span><img src='ico/sort.png' width='10' height='10' alt='' title='prim&auml;re Sortierung'></td>"
		."\n\t<td class='head'>Dienststelle</td>"
		."\n\t<td class='heads'>Bezirk</td>"
		."\n\t<td class='heads'><span class='wichtig'>Blatt</span></td>"
		."\n\t<td class='heads'>BVNR</td>"
		."\n\t<td class='head'>Buchungsart</td>"
		."\n\t<td class='head nwlink noprint'>Weitere Auskunft</td>\n</tr>";
	}

	$anlfdnr=$rowb["anlfdnr"];	// an BVNR
	$anlfdnr0=str_pad($anlfdnr, 4, "0", STR_PAD_LEFT); // mit führ.0
	$gml_b=$rowb["gml_id"];		// id des ber. Blattes
	$gml_s=$rowb["gml_s"];		// id der ber. B-Stelle
	$blart=$rowb["blattart"];

	$buch=$rowb["buchungsart"];	// Buchungsart Stelle berechtigt
	$bart=$rowb["bart"];		// BA entschl.
	$lfdnr=$rowb["lfdnr"];		// BVNR ber.
	$blatt=ltrim($rowb["blatt"], "0");
	$bvnr=str_pad($lfdnr, 4, "0", STR_PAD_LEFT);

	if ($zpaar) {$trclass='paa';} else {$trclass='unp';} // Farbwechsel je Zeile = Grundstück
	$zpaar=!$zpaar;

	echo "\n<tr class='".$trclass."'>"; // Der Teil "berechtigte Grundbücher" ist nach BVNR sortiert wie oberer Teil "Flurstücke"
		echo "\n\t<td><span class='wichtig'>".$anlfdnr0."</span></td>"
		."\n\t<td>"; // Amtsgericht,Grundbuchamt
			echo htmlentities($rowb["stellev"], ENT_QUOTES, "UTF-8")." ";
			echo DsKy($rowb["stelle"], 'Stelle-*').htmlentities($rowb["bezeichnung"], ENT_QUOTES, "UTF-8")
		."</td>"
		."\n\t<td>".DsKy($rowb["bezirk"], 'Grundbuch-Bezirk-*').htmlentities($rowb["beznam"], ENT_QUOTES, "UTF-8")."</td>"
		."\n\t<td><span class='wichtig'>".$blatt."</span></td>"
		."\n\t<td>".$bvnr."</td>"
		."\n\t<td title='".htmlentities($rowb["bartd"], ENT_QUOTES, "UTF-8")."'>".DsKy($buch, 'Buchungsart-*').$bart."</td>"
		."\n\t<td>"
			."\n\t\t<p class='nwlink noprint'>";
			// Bestand
			echo "\n\t\t\t".DsKy($blart, 'Blattart-*')."<a href='alkisbestnw.php?gkz=".$gkz."&amp;gmlid=".$gml_b.LnkStf()
			."#bvnr".$lfdnr."' title='Nachweis des berechtigten Blattes an einer Buchung auf ".$blattart."'>".$rowb["blattartv"]
			." \n\t\t\t<img src='ico/GBBlatt_link.png' width='16' height='16' alt=''></a>"
			."\n\t\t</p>"
		."</td>"
	."\n</tr>";
	$b++;
}
if ($b === 0) {
	if ($blattkey > 2000 ) { // Warnung nicht bei Grundbuchblatt 1000 und Katasterblatt 2000
		echo "\n<p class='err'>Keine berechtigten Bl&auml;tter zu ".$blattart." (".$blattkey.") gefunden.</p>";
	}
} else {
	echo "\n</table>";
	if ($i > 1) {
		echo "\n<p class='cnt'>Rechte anderer Buchungsstellen an ".$b." der ".$i." Buchungen</p>";
	}
}
pg_free_result($resb);

echo "\n<div class='buttonbereich noprint'>\n<hr>"
	."\n\t<a title='zur&uuml;ck' href='javascript:history.back()'><img src='ico/zurueck.png' width='16' height='16' alt='zur&uuml;ck'></a>&nbsp;";
if ($PrntBtn==true){echo "\n\t<a title='Drucken' href='javascript:window.print()'><img src='ico/print.png' width='16' height='16' alt='Drucken'></a>&nbsp;";}
if ($blattkey == 5000) { // Nicht bei "fiktives Blatt"
	echo "\n\t<img src='ico/download_gb_no.png' width='32' height='16' alt='Export' title='F&uuml;r ein fiktives Blatt ohne Eigent&uuml;mer ist ein CSV-Export nicht sinnvoll.'>"; 
} else {
	echo "\n\t<a title='Export als CSV' href='javascript:ALKISexport(\"".$gmlid."\")'><img src='ico/download_gb.png' width='32' height='16' alt='Export'></a>";
}
echo "&nbsp;\n</div>";
footer($gmlid, selbstverlinkung()."?", "");
?>
</body>
</html>
<?php
/*	alkisfshist.php

	ALKIS-Auskunft
	Author: Frank Jäger, Kommunales Rechenzentrum Minden-Ravensberg/Lippe (Lemgo)

	Flurstücks-Historie für ein Flurstückskennzeichen aus ALKIS PostNAS

	Version:
	2016-02-24 Version für norGIS-ALKIS-Import
	....
	2020-02-20 Authentifizierung ausgelegert in Function darf_ich()
	2020-12-03 function selbstverlinkung() statt $_SERVER['PHP_SELF']
	2020-12-15 Input-Validation und Strict Comparisation (===)
	2021-12-09 Neuer Parameter $PrntBtn (Drucken-Schaltfläche)
	2022-01-13 Neue Functions LnkStf(), DsKy()
	2022-07-05 PHP 8.1: Connection verwenden bei "pg_prepare" und "pg_execute", keine NULL-Werte in String-Functions verwenden
*/

function fzerleg($fs) {
/*	Flurstückskennzeichen (20) zerlegen als lesbares Format (wie im Balken):
	Dies FS-Kennz-Format wird auch als Eingabe in der Navigation akzeptiert 
   ....*....1....*....2
   ll    fff     nnnn
     gggg   zzzzz    __
*/
	$fst=rtrim($fs,"_");	
	$zer=substr ($fst, 2, 4)."-".ltrim(substr($fst, 6, 3), "0")."-<b>".ltrim(substr($fst, 9, 5),"0");
	$nenn=ltrim(substr($fst, 14), "0");
	if ($nenn != "") {$zer.="/".$nenn;}
	$zer.="</b>";
	return $zer; 
}

function vornach($dbarr) {
// Datenbank-Array-Feld zeilenweise ausgeben als Selbst-Link
	global $gkz, $showkey;
	if ($dbarr == "") {
		echo "(keine)";
	} else {
		$stri=trim($dbarr, "{}");
		$arr = explode(",",$stri);
		foreach($arr AS $val){
			echo "Flurst&uuml;ck <a title=' zur Flurst&uuml;ck Historie' href='".selbstverlinkung()."?gkz=".$gkz."&amp;fskennz=".$val.LnkStf()."'>".fzerleg($val)."</a><br>";
		}
	}
	return 0;
}

function gemkg_name($gkey) {
//	Schlüssel wird übergeben, Name dazu in der DB nachschlagen
	global $con;
	$sql ="SELECT bezeichnung FROM ax_gemarkung g WHERE g.gemarkungsnummer= $1 AND g.endet IS NULL LIMIT 1;";
	$v=array($gkey);
	$res=pg_prepare($con, "", $sql);
	$res=pg_execute($con, "", $v);
	if (!$res) {echo "\n<p class='err'>Fehler bei Gemarkung.</p>";}
	$gmkg="";
	$zeianz=pg_num_rows($res);
	if ($zeianz > 1){
		echo "\n<p class='err'>Die Abfrage liefert mehr als eine (".$zeianz.") Gemarkung!</p>";
		if ($dbg > 2) {echo "\n<p class='dbg'>SQL=<br>".htmlentities(str_replace("$1","'".$gkey."'",$sql), ENT_QUOTES, "UTF-8")."</p>";}
	} elseif ($zeianz === 0) {
		echo "\n<p class='err'>Gemarkung ".$gkey." ist unbekannt.</p>";
		return;
	}
	if ($row = pg_fetch_assoc($res)) {
		$gmkg=$row["bezeichnung"];
	}
	return $gmkg;
}

function such_vor_arr($fsk) {
// Suchen Vorgänger zum aktuellen Flurst. Ausgabe von Selbst-Links Zeilenweise in <td>.
// Akt. FS und hist.FS hat keine Verweise auf Vorgänger. Darum in den Nachfolger-Verweisen von Hist.-FS suchen.
	global $gkz, $con, $dbg, $showkey, $filtkreis, $filtgem;

	$sqlv="SELECT 'h' AS ftyp, h.gml_id, h.flurstueckskennzeichen FROM ax_historischesflurstueck h "
	."WHERE $1 = ANY (h.nachfolgerflurstueckskennzeichen) AND h.endet IS NULL "
	."UNION SELECT 'o' AS ftyp, o.gml_id, o.flurstueckskennzeichen FROM ax_historischesflurstueckohneraumbezug o "
	."WHERE $1 = ANY (o.nachfolgerflurstueckskennzeichen) AND o.endet IS NULL ORDER BY flurstueckskennzeichen";

	$v=array($fsk);
	$resv = pg_prepare($con, "", $sqlv);
	$resv = pg_execute($con, "", $v);
	if (!$resv) {
		echo "\n<p class='err'>Fehler bei Vorg&auml;nger-FS.</p>";
		if ($dbg > 2) {echo "\n<p class='dbg'>SQL=<br>".str_replace("$1", "'".$fsk."'", $sqlv)."</p>";}
	}
	$zv=0;
	while($rowv = pg_fetch_assoc($resv)) {
		$ftyp=$rowv["ftyp"];
		$gmlv=$rowv["gml_id"];
		$vfsk=$rowv["flurstueckskennzeichen"];
		echo "Flurst&uuml;ck <a title='Historie des Vorg&auml;ngerflurst&uuml;cks' href='".selbstverlinkung()."?gkz=".$gkz."&amp;fskennz=".$vfsk."&amp;gmlid=".$gmlv.LnkStf()."'>".fzerleg($vfsk)."</a><br>";
		$zv++;
	}
	if ($zv === 0) {
		echo "(keine)";
	}
	return;
}

// Start
ini_set("session.cookie_httponly", 1);
session_start();
$showkey="n"; $nodebug=""; // Var. aus Parameter initalisieren
$cntget = extract($_GET); // alle Parameter in Variable umwandeln

// strikte Validierung aller Parameter
if (isset($gmlid)) {
	if (!preg_match('#^[0-9A-Za-z]{16}$#', $gmlid)) {die("Eingabefehler gmlid");}
	$fskennz='';
} else { // Alternativ
	$gmlid='';
	if (isset($fskennz)) { // llgggg-fff-11111/222 oder z.B.'052647002001910013__' oder '05264700200012______'
		if (!preg_match('#^[0-9\-_/]{8,20}$#', $fskennz)) {die ("Eingabefehler fskennz");}
	} else {
		$fskennz='';
		die("Fehlender Parameter");
	}
}
if (isset($gkz)) {
	if (!preg_match('#^[0-9]{3}$#', $gkz)) {die("Eingabefehler gkz");}
} else {
	die("Fehlender Parameter");
}
if (!preg_match('#^[j|n]{0,1}$#', $showkey)) {die ("Eingabefehler showkey");}
if ($showkey === "j") {$showkey=true;} else {$showkey=false;} // "j"/"n" als boolean umwandeln, ist praktischer abzufragen, wird oft gebraucht
if (!preg_match('#^j{0,1}$#', $nodebug)) {die("Eingabefehler nodebug");}

include "alkis_conf_location.php";
include "alkisfkt.php";

echo <<<END
<!doctype html>
<html lang="de">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>ALKIS Flurst&uuml;cks-Historie</title>
	<link rel="stylesheet" type="text/css" href="alkisauszug.css">
	<link rel="shortcut icon" type="image/x-icon" href="ico/Flurstueck_Historisch.ico">
</head>
<body>
END;

$erlaubnis = darf_ich(); if ($erlaubnis === 0) { die('<p class="stop1">Abbruch</p></body>'); }
$dbg=$debug;
if ($nodebug === "j") {$dbg=0;} 

$con = pg_connect($dbconn." options='--application_name=ALKIS-Auskunft_alkisfshist.php'");
if (!$con) echo "\n<p class='err'>Fehler beim Verbinden der DB</p>";

// Such-Parameter bekommen? Welche?
if ($gmlid != "") { // Ja, die GML wurde uebergeben
	$parmtyp="GML";
	$parmval=$gmlid;
	$whereclause="WHERE gml_id= $1 ";
	$v = array($gmlid);
} elseif ($fskennz != "") { // Alternativ: Flurst.-Kennz. übergeben
		$parmtyp="Flurst&uuml;ckskennzeichen";
		$parmval=$fskennz;
		$whereclause="WHERE flurstueckskennzeichen= $1 ";
		$v=array(fskenn_dbformat($fskennz));
} else { // Pfui!
		$parmtyp="";
		die("Fehlender Parameter");
}

if ($parmtyp != "") { // einer der beiden erlaubten Fälle
	// UNION-Abfrage auf 3 ähnliche Tabellen, darin aber immer nur 1 Treffer.
	$felder="gml_id, flurnummer, cast(zaehler AS character varying), cast(nenner AS character varying), flurstueckskennzeichen, amtlicheflaeche, to_char(cast(zeitpunktderentstehung AS date),'DD.MM.YYYY') AS zeitpunktderentstehung, gemarkungsnummer, ";

	if ($filtgem === '') { // Filter Gemeinde ?
		$wheref='';
		$whereh='';
		$whereo='';
	} else { // Zusätze zur WHERE-Clausel
		$wheref=" AND f.gemeindezugehoerigkeit_kreis = '".$filtkreis."' AND f.gemeindezugehoerigkeit_gemeinde = '".$filtgem."' ";
		$whereh=" AND (h.gemeindezugehoerigkeit_kreis IS NULL OR h.gemeindezugehoerigkeit_kreis = '".$filtkreis."' ) AND (h.gemeindezugehoerigkeit_gemeinde IS NULL OR h.gemeindezugehoerigkeit_gemeinde = '".$filtgem."') ";
		$whereo=" AND (o.gemeindezugehoerigkeit_gemeinde IS NULL OR o.gemeindezugehoerigkeit_gemeinde = '".$filtgem."') ";
	}

	$sqlu ="SELECT 'a' AS ftyp, ".$felder."null::character varying[] AS nach, null::character varying[] AS vor, zeigtaufexternes_art AS zart, zeigtaufexternes_name AS zname FROM ax_flurstueck f ".$whereclause.$wheref." AND f.endet IS NULL "
	."UNION SELECT 'h' AS ftyp, ".$felder."nachfolgerflurstueckskennzeichen AS nach, null AS vor, zeigtaufexternes_art AS zart, zeigtaufexternes_name AS zname FROM ax_historischesflurstueck h ".$whereclause.$whereh." AND h.endet IS NULL "
	."UNION SELECT 'o' AS ftyp, ".$felder."nachfolgerflurstueckskennzeichen AS nach, vorgaengerflurstueckskennzeichen AS vor, zeigtaufexternes_art AS zart, zeigtaufexternes_name AS zname FROM ax_historischesflurstueckohneraumbezug o ".$whereclause.$whereo." AND o.endet IS NULL;";

	$resu = pg_prepare($con, "", $sqlu);
	$resu = pg_execute($con, "", $v);
	if ($rowu = pg_fetch_assoc($resu)) {
		$ftyp=$rowu["ftyp"];
		$gmkgnr=$rowu["gemarkungsnummer"];
		$flurnummer=$rowu["flurnummer"];
		$zaehler=$rowu["zaehler"];
		$nenner=$rowu["nenner"];
		$flstnummer=$zaehler;
		if ($nenner > 0) {$flstnummer.="/".$nenner;} // BruchNr
		$fskenn=$rowu["flurstueckskennzeichen"];
		$flae=number_format($rowu["amtlicheflaeche"],0,",",".") . " m&#178;";
		$gemkname= gemkg_name($gmkgnr);
		$vor=$rowu["vor"];
		$nach=$rowu["nach"];
		$entsteh=$rowu["zeitpunktderentstehung"];
		$zeart=$rowu["zart"];
		$zename=$rowu["zname"];
		if (is_null($zename)) {$zename="";}
		if ($gmlid == "") {$gmlid=$rowu["gml_id"];} // für selbst-link-Umschalter über footer

		switch ($ftyp) { // Diff. Hist./Akt.
			case 'a': 
				$wert = "aktuell";
				$ico= "Flurstueck.png";
				$cls= "kennzfs";	
			break;
			case 'h': 
				$wert = "historisch<br>(mit Raumbezug)";
				$ico= "Flurstueck_Historisch.png"; //
				$cls= "kennzfsh";
			break;
			case 'o': 
				$wert = "historisch<br>ohne Raumbezug";
				$ico= "Flurstueck_Historisch_oR.png";
				$cls= "kennzfsh";
			break;
			default:
				$wert = "<b>nicht gefunden: ".$parmtyp." = '".$parmval."'</b>";
				$ico= "Flurstueck_Historisch.png";
				$cls= "kennzfsh";
			break;
		}
	} else {
		if ($dbg > 1) {
			echo "<br><p class='err'>Fehler! Kein Treffer f&uuml;r ".$parmtyp." = '".$parmval."'</p><br>";
			if ($dbg > 2) {
				echo "<p class='dbg'>SQL=<br>".str_replace("$1", "'".$v[0]."'", $sqlu)."</p>";
			}
		}
	}
}

// Balken
echo "<p class='balken fshis'>ALKIS Flurst&uuml;ck ".$gmkgnr."-".$flurnummer."-".$flstnummer."&nbsp;</p>";
echo "\n<h2>Flurst&uuml;ck Historie</h2>";

echo "\n<table class='outer'>\n<tr>\n\t<td>"
	."\n\t<tr>\n\t\t<td class='ll'><img src='ico/".$ico."' width='16' height='16' alt=''> Kennzeichen:</td>" // Links
	."\n\t\t<td>" // Mitte
	."\n\t<table class='".$cls."' title='Flurst&uuml;ckskennzeichen'>\n\t<tr>" // innere Tabelle Kennzeichen
		."\n\t\t<td class='head'>Gemarkung</td>\n\t\t<td class='head'>Flur</td>\n\t\t<td class='head'>Flurst-Nr.</td>\n\t</tr>"
		."\n\t<tr>\n\t\t<td title='Gemarkung'>".DsKy($gmkgnr, 'Gemarkungsnummer').$gemkname."&nbsp;</td>"
		."\n\t\t<td title='Flurnummer'>".$flurnummer."</td>"
		."\n\t\t<td title='Flurst&uuml;cksnummer (Z&auml;hler / Nenner)'><span class='wichtig'>".$flstnummer."</span></td>\n\t</tr>"
	."\n\t</table>"
."\n\t</td>\n\t<td>";
fortfuehrungen($entsteh, $zeart, $zename);
echo "\n\t</td>\n</tr>\n</table>";

if ($ftyp === "a") { // Aktuell -> Historie
	echo "\n<p class='nwlink noprint'>weitere Auskunft: "
	."<a href='alkisfsnw.php?gkz=".$gkz."&amp;gmlid=".$gmlid.LnkStf()."&amp;eig=n' title='Flurst&uuml;cksnachweis'>Flurst&uuml;ck "
	."<img src='ico/Flurstueck_Link.png' width='16' height='16' alt=''></a>";
}
echo "\n<hr>"
."<table class='outer'>"
	."\n<tr>
		<td class='head'>Flurst&uuml;ck</td>
		<td class='head'>Vorg&auml;nger</td>
		<td class='head'>Nachfolger</td>
	</tr>"; // Head
	
	// Sp.1: Flurstück
	echo "\n<tr>\n\t<td>"
		."<img src='ico/".$ico."' width='16' height='16' alt=''> ".$wert
		."<br>Fl&auml;che <span class='flae'>".$flae."</span>"
	."</td>";

	// Sp.2: Vorgänger
	echo "\n\t<td>";
	switch ($ftyp) { // Diff. Hist./Akt.
		case 'a':
			such_vor_arr($fskenn);
		break;
		case 'h':
			such_vor_arr($fskenn);
		break;
		case 'o':
			vornach($vor);
		break;
	}
	echo"</td>";

	// Sp.3: Nachfolger
	echo "\n\t<td>";
		vornach($nach);
	echo "</td>\n</tr>"
."\n</table>";

if ($dbg > 1) {
	$z=1;
	while($rowu = pg_fetch_assoc($resu)) {
		$ftyp=$rowu["ftyp"];
		echo "<p class='dbg'>Mehr als EIN Eintrag gefunden: '".$ftyp."' (".$z.")</p>";
		$z++;
	}
}
echo "<div class='buttonbereich noprint'>\n<hr>"
	."\n\t<a title='zur&uuml;ck' href='javascript:history.back()'><img src='ico/zurueck.png' width='16' height='16' alt='zur&uuml;ck'></a>&nbsp;";
if ($PrntBtn==true){echo "\n\t<a title='Drucken' href='javascript:window.print()'><img src='ico/print.png' width='16' height='16' alt='Drucken'></a>&nbsp;";}
echo "\n</div>";

footer($gmlid, selbstverlinkung()."?", "");
?>

</body>
</html>
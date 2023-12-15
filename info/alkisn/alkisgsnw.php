<?php
/*	alkisgsnw.php

	ALKIS-Auskunft
	Author: Frank Jäger, Kommunales Rechenzentrum Minden-Ravensberg/Lippe (Lemgo)

	Grundstücksnachweis für ein Grundstück (Buchung) aus ALKIS PostNAS

	Version:
	2018-05-03 Neues Modul
	...
	2020-12-03 function selbstverlinkung() statt $_SERVER['PHP_SELF']
	2020-12-15 Input-Validation und Strict Comparisation (===)
	2021-12-09 Neue Parameter: $katAmtMix (Kataster-Amt-Mix), $PrntBtn (Drucken-Schaltfläche)
	2022-01-13 Neue Functions LnkStf(), DsKy()
	2022-07-05 PHP 8.1: Connection verwenden bei "pg_prepare" und "pg_execute", keine NULL-Werte in String-Functions verwenden
ToDo: 
- Den Fall berücksichtigen, dass die Buchung vorhanden ist, aber das GB nicht (Modellschwäche). Meldungen ausgeben.
- bei Aufruf herrschende Buchung mit mehreren dienenden Buchungen: Links zu den dienenden Buchungen anzeigen
*/

function get_GS_Daten($gmlid, $gskennz) {
// Daten zur Buchungsstelle (GS = Grundstück) aus der DB lesen.
// Suche wahlweise über die GML-ID oder über das Grundstücks-Kennzeichen (Bezirk-Blatt-LfdNr) aus der URL.
	global $gkz, $showkey, $dbg, $defland, $gsbez, $gsblatt, $gslfdnr, $gsbart, $con;

	$sqlgs="SELECT s.gml_id, b.bezirk, b.buchungsblattnummermitbuchstabenerweiterung AS blatt, s.laufendenummer, s.buchungsart "
		."FROM ax_buchungsstelle s JOIN ax_buchungsblatt b ON s.istbestandteilvon=b.gml_id ";
	// Falls das Buchungsblatt fehlt (Modellschwäche) wäre ein LEFT JOIN hier nützlich. Das Fehlen aber kontrollieren!

	if ($gskennz == '') { // normaler Link über gmlid
		$sqlgs.="WHERE s.gml_id= $1 ";
		$v=array($gmlid);
	} else { // Kennzeichen "Bezirk-Blatt-BVNR" alternativ zur gml_id
		$arr=explode("-", $gskennz, 4); // zerlegen
		$zbez=trim($arr[0]); // Bezirk
		if (strlen($zbez) === 6) {
			$land=substr($zbez, 0, 2);
			$zbez=substr($zbez, 2, 4);
		} else { // kein schöner Land ..
			$land=$defland; // Default aus config
		}
		$zblatt=$arr[1];
		if (preg_match('#^[0-9]{1,6}$#', $zblatt)) { // Nur numerisch
			$zblatt=str_pad($zblatt, 6, "0", STR_PAD_LEFT)." "; // 6 Nr + 1 blank
		} elseif (preg_match('#^[0-9A-Z]{1,7}$#', $zblatt)) { // +++ A nur in LETZTER Stelle prüfen
			$zblatt=str_pad($zblatt, 7, "0", STR_PAD_LEFT); // 6 Nr + 1 "A", gesamt 7
		} else {
			die("Fehler in Buchungsblatt im Parameter 'gskennz'.");
		}
		$zlfdnr=str_pad($arr[2], 4, "0", STR_PAD_LEFT); // Lfd.-Nr./BVNR
		$sqlgs.="WHERE b.land= $1 AND b.bezirk= $2 AND b.buchungsblattnummermitbuchstabenerweiterung= $3 AND s.laufendenummer= $4 ";
		$v=array($land, $zbez, $zblatt, $zlfdnr);
	}
	// egal ob Suche mit gmlid ODER Kennzeichen
	$sqlgs.="AND b.endet IS NULL AND s.endet IS NULL;";
	$resgs=pg_prepare($con, "", $sqlgs);
	$resgs=pg_execute($con, "", $v);
	if ($rowgs=pg_fetch_assoc($resgs)) {
		$gmlid=$rowgs["gml_id"];
		$gsbez=$rowgs["bezirk"];
		$gsblatt=$rowgs["blatt"];
		$gslfdnr=$rowgs["laufendenummer"];
		$gsbart=$rowgs["buchungsart"];
	} else {
		echo "\n<p class='err'>Fehler! Kein Treffer f&uuml;r Grundst&uuml;ckskennzeichen='".$gskennz."'</p>\n</body>\n</html>";
		return "";
	}
	pg_free_result($resgs);
	return $gmlid;
}

function Back2theRoots($gmlid) {
// Die Buchungsstelle aus dem Aufruf-Parameter - wenn eindeutig möglich - iterativ zurück führen auf die dienende Buchungsstelle, 
// auf der die Flurstücke gebucht sind (Buchungsart="Grundstück" oder Blattart="fiktives Blatt").
// Der Grundstücksnachweis wird aus anderen Modulen nur für die "Grundstück"-Buchung aufgerufen, so dass diese Suche nicht notwendig ist.
// Bei Aufrufen von außen kann dies aber sinnvoll sein.

	global $gkz, $dbg, $showkey, $gerooted, $con;
	$gd=$gmlid; // gml dienend

	// BS-herrschend (bekannt)  >an[]>  BS-dienend (gesucht)
	$sql="SELECT d.gml_id, d.laufendenummer FROM ax_buchungsstelle d JOIN ax_buchungsstelle h ON d.gml_id=any(h.an) "
		."WHERE h.gml_id = $1 and d.endet IS NULL AND h.endet IS NULL ORDER BY d.laufendenummer;";

	while($gd != "") {
		$gr=$gd; // gml Return
		$v=array($gd);
		$res=pg_prepare($con, "", $sql);
		$res=pg_execute($con, "", $v);
		$zeianz=pg_num_rows($res);
		if ($zeianz == 0){ // sollte nicht vorkommen, die Buchungsart "Grundstück" ruft dies NICHT auf
			if ($dbg > 1 ) {echo "\n<p class='err'>Keine 'diendende' Buchung zur Buchung '".$gd."'</p>";}
			$gd="";			
		} elseif ($zeianz == 1){
			if ($dbg > 1 ) {echo "\n<p class='dbg'>Eine 'diendende' Buchung zur Buchung '".$gd."'</p>";}
			$row=pg_fetch_assoc($res);
			$gd=$row["gml_id"];
		} else { // > 1 // Seltener Sonderfall
			if ($dbg > 1 ) {echo "\n<p class='dbg'>".$zeianz." 'diendende' Buchungen zur Buchung '".$gd."'</p>";}
			$gerooted=false; // Root (Grundstück) wird nicht erreicht
			$gd=""; // wenn mehrere (.an=Array[]), dann nicht eindeutig rückführbar
			echo "\n<table class='fs'>\n<tr>\n\t<td class='heads'>Hinweis</td>"
			."\n\t<td class='head nwlink noprint' title='Verlinkung zu anderen Nachweis-Arten und verbundenen Objekten'>weitere Auskunft</td>"
			."\n</tr>";
			echo "\n<tr>\n\t<td>Die angeforderte Buchung hat Rechte an ".$zeianz." anderen Buchungen."
			."<br>F&uuml;r die Anzeige der Flurst&uuml;cke muss eine dieser Grundst&uuml;cks-Buchungen gew&auml;hlt werden.</td>"
			."\n\t<td>\n\t\t<p class='nwlink noprint'>";
			while($row=pg_fetch_assoc($res)) {
				$gml_d=$row["gml_id"];
				$bvnr=ltrim($row["laufendenummer"], '0');
				echo "\n\t\t\t<a href='alkisgsnw.php?gkz=".$gkz."&amp;gmlid=".$gml_d.LnkStf()
				."' title='Grundst&uuml;cksnachweis'>Buchung ".$bvnr
				." <img src='ico/Grundstueck_Link.png' width='16' height='16' alt=''></a><br>";
			}
			echo "\n\t\t</p>\n\t</td>\n</tr>\n</table>";
		}
	}
	pg_free_result($res);
	return $gr;
}

// S t a r t
ini_set("session.cookie_httponly", 1);
session_start();
$showkey="n"; $nodebug="";
$cntget=extract($_GET); // Parameter in Variable

// Validierung
if (isset($gmlid)) { // gml der Buchungsstelle (Aufruf)
	if (!preg_match('#^[0-9A-Za-z]{16}$#', $gmlid)) {die("Eingabefehler gmlid");}
	$gskennz='';
} else { // Alternativ
	$gmlid='';
	if (isset($gskennz)) { // llgggg-bbbbbz-nnnn 
		if (!preg_match('#^[0-9\-_/]{8,18}$#', $gskennz)) {die ("Eingabefehler gskennz");}
	} else {
		$gskennz='';
		die("Fehlender Parameter");
	}
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
	<title>ALKIS Grundst&uuml;cksnachweis</title>
	<link rel="stylesheet" type="text/css" href="alkisauszug.css">
	<link rel="shortcut icon" type="image/x-icon" href="ico/Grundstueck.ico">
	<script>
		function ALKISexport(gmlid) {
			window.open('alkisexport.php?gkz=<?php echo $gkz;?>&tabtyp=buchung&gmlid=' + gmlid);
		}
	</script>
</head>
<body>
<?php
$erlaubnis = darf_ich(); if ($erlaubnis === 0) { die('<p class="stop1">Abbruch</p></body>'); }
$dbg=$debug;
if ($nodebug === "j") {$dbg=0;} 
$con = pg_connect($dbconn." options='--application_name=ALKIS-Auskunft_alkisgsnw.php'");
if (!$con) echo "\n<p class='err'>Fehler beim Verbinden der DB</p>";

$gml_anfrd=$gmlid;	// ursprüngliche Anforderung aus URL merken
$gerooted=true;		// Auskunft beginnt mit (dienendem) Grundstück
$gmlid=get_GS_Daten($gmlid, $gskennz); // nachschauen, Variablen setzen, Keine Ausgabe
if ($gmlid == "") { // nix gefunden
	die("Kein Treffer");
}
echo "<p class='balken gskennz'>ALKIS Grundst&uuml;ck ".$gsbez."-".rtrim($gsblatt)."-".$gslfdnr."&nbsp;</p>\n\n<h2>Grundst&uuml;ck</h2>"; // Balken

// F l u r s t ü c k e
echo "\n<h3><img src='ico/Flurstueck.png' width='16' height='16' alt=''> Flurst&uuml;cke</h3>";

if ($gsbart > 1102){ // Recht an ..
	$gmlid_r=Back2theRoots($gmlid);
	if ($gmlid_r != $gmlid) { // wurde hoch gerückt
		$gmlid=get_GS_Daten($gmlid_r, ""); // damit weiter arbeiten
	}
}
if ($gerooted) { // // Buchungsart (aufgeteiltes) Grundstück (nicht Recht an ..)
	echo "\n<table class='fs'>\n<tr>" // Kopfzeile
		."\n\t<td class='heads'>Gemarkung</td>"
		."\n\t<td class='heads'>Flur</td>"
		."\n\t<td class='heads fsnr' title='Flurst&uuml;cksnummer (Z&auml;hler / Nenner)'><span class='wichtig'>Flurst.</span></td>"
		."\n\t<td class='head fla'>Fl&auml;che</td>"
		."\n\t<td class='head nwlink noprint' title='Verlinkung zu anderen Nachweis-Arten und verbundenen Objekten'>weitere Auskunft</td>"
	."\n</tr>";
	//++ Lage oder Nutzung zum FS in eine zus. Spalte oder Zeile?
	//++ Tabelle hat noch Platz, SQL in der Loop oder Subquery wäre möglich, weil i.d.R. nur wenige FS je GS gebucht sind.

	$sqlfs ="SELECT g.gemarkungsnummer, g.bezeichnung, f.gml_id, f.flurnummer, f.zaehler, f.nenner, f.amtlicheflaeche "
	."FROM ax_flurstueck f LEFT JOIN ax_gemarkung g ON f.land=g.land AND f.gemarkungsnummer=g.gemarkungsnummer ".UnqKatAmt("f","g")
	."WHERE f.istgebucht = $1 AND f.endet IS NULL AND g.endet IS NULL ";
	if ($filtgem === '' ) { // ungefiltert
		$v=array($gmlid);
	} else {
		$sqlfs.="AND f.gemeindezugehoerigkeit_kreis = $2 AND f.gemeindezugehoerigkeit_gemeinde = $3 "; // Zuständiges Gebiet
		$v=array($gmlid, $filtkreis, $filtgem);
	}
	$sqlfs.="ORDER BY f.gemarkungsnummer, f.flurnummer, f.zaehler, f.nenner;";
	$resfs = pg_prepare($con, "", $sqlfs);
	$resfs = pg_execute($con, "", $v);
	if (!$resfs) {echo "\n<p class='err'>Fehler bei Flurst&uuml;ck</p>";}

	$j=0;
	$zpaar=false;
	while($rowfs = pg_fetch_assoc($resfs)) {
		$flur= $rowfs["flurnummer"];
		$fskenn=$rowfs["zaehler"];
		if ($rowfs["nenner"] != "") {$fskenn.="/".$rowfs["nenner"];}
		$flae=number_format($rowfs["amtlicheflaeche"],0,",",".") . " m&#178;";

		if ($zpaar) {$trclass='paa';} else {$trclass='unp';} // Farbwechsel
		$zpaar=!$zpaar;
		echo "\n<tr class='".$trclass."'>"; // eine Zeile je Flurstück
			echo "\n\t<td>".DsKy($rowfs["gemarkungsnummer"], 'Gemarkungsnummer').$rowfs["bezeichnung"]."</td>"
			."\n\t<td>".$flur."</td>"
			."\n\t<td class='fsnr'><span class='wichtig'>".$fskenn."</span></td>"
			."\n\t<td class='fla'>".$flae."</td>"
			."\n\t<td>\n\t\t<p class='nwlink noprint'>"
				."\n\t\t\t<a href='alkisfsnw.php?gkz=".$gkz."&amp;gmlid=".$rowfs["gml_id"]."&amp;eig=n".LnkStf()
				."' title='Flurst&uuml;cksnachweis'>Flurst&uuml;ck "
				."<img src='ico/Flurstueck_Link.png' width='16' height='16' alt=''>"
				."</a>\n\t\t</p>"
			."\n\t</td>"
		."\n</tr>";
		$j++;
	}
	pg_free_result($resfs);
	if ($j === 0) {echo "\n<tr class='unp'>\n\t<td colspan='5'><p class='warn'>Keine Flurst&uuml;cke im berechtigten Bereich.</p></td>\n</tr>";}
	echo "\n</table>";
}

// Rechte anderer GS an diesem GS
// Dieser Teil ist fast identisch mit "Flurstücksnachweis", Kommentare siehe dort.
$bartgrp="";
$barttypgrp="";
echo "\n<table class='outer'>";
	$stufe=1;
	$gezeigt = buchung_anzg($gmlid, "j", false, "", 2);
	$anzber=ber_bs_zaehl($gmlid);
	$verf_next = array($gmlid);
	while ($anzber > 0 ) {
		$verf_akt=$verf_next;
		$verf_next=array();
 		$stufe++;
		$i=0;
		foreach($verf_akt as $gml_ber_bs) {
			$i++;
			if (ber_bs_zaehl($gml_ber_bs) > 0) {
				$verf_neu=ber_bs_anzg($gml_ber_bs, "j", false, "", $gml_anfrd);
				$anz_neu=count($verf_neu);
				if ($anz_neu > 0) {
					$verf_next=array_merge($verf_next, $verf_neu);
				}
			}
		}
		$anzber=count($verf_next);
	}
echo "\n</table>\n";
pg_close($con);

echo "<div class='buttonbereich noprint'>\n<hr>"
	."\n\t<a title='zur&uuml;ck' href='javascript:history.back()'><img src='ico/zurueck.png' width='16' height='16' alt='zur&uuml;ck'></a>&nbsp;";
if ($PrntBtn==true){echo "\n\t<a title='Drucken' href='javascript:window.print()'><img src='ico/print.png' width='16' height='16' alt='Drucken'></a>&nbsp;";}
if ($stufe < 3) {
	echo "\n\t<a title='Export als CSV' href='javascript:ALKISexport(\"".$gmlid."\")'><img src='ico/download_gs.png' width='32' height='16' alt='Export'></a>";
} else { // Export CSV wäre unvollständig bei Buchungen auf mehr als 2 Ebenen. 2 Ebenen werden im View über UNION und einem Zweig mit 2x "an"-Relation abgefangen.
	echo "\n\t<img src='ico/download_gs_no.png' width='32' height='16' alt='Export' title='Komplexe Buchungen über ".$stufe." Ebenen sind nicht als lineare CSV-Datei exportierbar'>";
}
echo "&nbsp;\n</div>";

footer($gmlid, selbstverlinkung()."?", "");
?>
</body>
</html>
<?php
/*	alkisbauwerk.php

	ALKIS-Buchauskunft
	Author: Frank Jäger, Kommunales Rechenzentrum Minden-Ravensberg/Lippe (Lemgo)
 
	Bauwerksdaten

	Typen von Bauwerken (jeweils eigene Tabellen mit unterschiedlicher Struktur):
	 1 Bauwerk im Verkehrsbereich
	 2 Bauwerk im Gewässerbereich
	 3 Sonstiges Bauwerk oder sonstige Einrichtung 51009
	 4 Bauwerk oder Anlage für Industrie und Gewerbe 51002
	 5 Bauwerk oder Anlage für Sport, Freizeit und Erholung 51006
	 6 Leitung 51005
	 7 Transportanlage 51004
	 8 Turm 51001
	 9 Vorratsbehälter, Speicherbauwerk 51003
	10 Historisches Bauwerk oder historische Einrichtung 51007
	11 Heilquelle, Gasquelle 51008 
	12 Einrichtung in öffentlichen Bereichen 51010
	(13 Besonderer Bauwerkspunkt 51011) fehlt noch

	Version:
	2021-03-11 Neues Modul
	2021-12-09 Neue Parameter: $katAmtMix (Kataster-Amt-Mix), $PrntBtn (Drucken-Schaltfläche). Debugging verbessert.
	2022-01-13 Neue Functions LnkStf(), DsKy()
	2022-02-23 Neue Bauwerks-Typen 10-12
	2022-07-05 PHP 8.1: Connection verwenden bei "pg_prepare" und "pg_execute", keine NULL-Werte in String-Functions verwenden
	2022-11-02 Fehlerkorrektur: break in switch

ToDo:
	- Vom Bauwerk überlagerte Flurstücke über geom. Verschneidung ermitteln und verlinken.
	- Icon für "Bauwerk" machen! Ggf. einzeln für jede Art?
*/

// Ein Bauwerk (bw_) "gehört zu" (_gz_) (Relation) einem Gebäude. Zu diesem Gebäude die Lage-Zeilen als Tabellen ausgeben
function bw_gz_lage($gmlgz) {
	global $gkz, $dbg, $con;
	// HAUPTgebäude  Geb >zeigtAuf> lage (mehrere)
	$sqlgz ="SELECT 'm' AS ltyp, l.gml_id AS lgml, s.lage, s.bezeichnung, l.hausnummer, '' AS laufendenummer, p.bezeichnung as gemeinde "
	."FROM ax_gebaeude g JOIN ax_lagebezeichnungmithausnummer l ON l.gml_id=ANY(g.zeigtauf) "
	."JOIN ax_lagebezeichnungkatalogeintrag s ON l.kreis=s.kreis AND l.gemeinde=s.gemeinde AND l.lage=s.lage "	
	."JOIN ax_gemeinde p ON s.land=p.land AND s.regierungsbezirk=p.regierungsbezirk AND s.kreis=p.kreis AND s.gemeinde=p.gemeinde ".UnqKatAmt("s","p")
	."WHERE g.gml_id= $1 AND g.endet IS NULL AND l.endet IS NULL AND s.endet IS NULL ";
	// UNION - oder NEBENgebäude  Geb >hat> Pseudo
	$sqlgz.="UNION SELECT 'p' AS ltyp, l.gml_id AS lgml, s.lage, s.bezeichnung, l.pseudonummer AS hausnummer, l.laufendenummer, p.bezeichnung as gemeinde "
	."FROM ax_gebaeude g JOIN ax_lagebezeichnungmitpseudonummer l ON l.gml_id=g.hat "
	."JOIN ax_lagebezeichnungkatalogeintrag s ON l.kreis=s.kreis AND l.gemeinde=s.gemeinde AND l.lage=s.lage "
	."JOIN ax_gemeinde p ON s.land=p.land AND s.regierungsbezirk=p.regierungsbezirk AND s.kreis=p.kreis AND s.gemeinde=p.gemeinde ".UnqKatAmt("s","p")	
	."WHERE g.gml_id= $1 AND g.endet IS NULL AND l.endet IS NULL AND s.endet IS NULL ";

	$sqlgz.="ORDER BY bezeichnung, hausnummer, laufendenummer;";
	$v = array($gmlgz);
	$resgz = pg_prepare($con, "", $sqlgz);
	$resgz = pg_execute($con, "", $v);
	if (!$resgz) {
		echo "\n<p class='err'>Fehler bei Lage mit HsNr. zum Bauwerk</p>";
		if ($dbg > 1) {
			echo "<p class='dbg'>Fehler:".pg_result_error($resgz)."</p>";
			if ($dbg > 2) {echo "\n<p class='dbg'>SQL=<br>".str_replace("$1", "'".$gmlgz."'", $sqlgz)."</p>";}
		}
	} else {
		$erst=true;
		while($rowgz = pg_fetch_assoc($resgz)) { // LOOP: Lagezeilen
			if ($erst) {
				echo "\n<tr>\n\t<td class='li'>Lage</td>";
				$erst=false;
			} else {
				echo "\n<tr>\n\t<td class='li'>&nbsp;</td>";
			}
			$ltyp=$rowgz["ltyp"];
			$skey=$rowgz["lage"];
			$snam=htmlentities($rowgz["bezeichnung"], ENT_QUOTES, "UTF-8");
			$hsnr=$rowgz["hausnummer"];
			$hlfd=$rowgz["laufendenummer"];
			$gmllag=$rowgz["lgml"];
			$gemeinde=htmlentities($rowgz["gemeinde"], ENT_QUOTES, "UTF-8");
			if ($ltyp === "p") {
				$lagetitl="Nebengeb&auml;ude Nr. ".$hlfd;
				$lagetxt=$snam." ".$hsnr." (".$hlfd."), ".$gemeinde;
			} else {
				$lagetitl="Hauptgebäude - HsNr. ".$hsnr;
				$lagetxt=$snam." ".$hsnr.", ".$gemeinde;
			}
			echo "\n\t<td class='adrlink'><a title='".$lagetitl."' href='alkislage.php?gkz=".$gkz."&amp;gmlid=".$gmllag."&amp;ltyp=".$ltyp.LnkStf()."'>"
			.DsKy($skey, 'Stra&szlig;en-*').$lagetxt."&nbsp;<img src='ico/Lage_mit_Haus.png' width='16' height='16' alt=''></a>&nbsp;";
			echo "</td>\n\t<td><p class='erkli'>Adresse: ".$lagetitl."</p></td>\n</tr>";
		}
		pg_free_result($resgz);
	}
}

// Eine Zeile der HTML-Tabelle ausgeben
function tab_zeile($titel, $key, $inhalt, $ea, $ei) {
	global $showkey, $allefelder, $dbg;
	if ($inhalt != "" OR $allefelder) {
		echo "\n<tr>\n\t<td class='li'>".$titel."</td>\n\t<td class='fett'>";
		if ($showkey and $key != '') {echo "<span class='key'>(".$key.")</span> ";}
		echo $inhalt."</td>\n\t<td>";
		if ($ea != '') {echo "\n\t\t<p class='erklk'>".$ea."</p>";}	// Erkl. Attribut (allgemein)
		if ($ei != '') {echo "\n\t\t<p class='erkli'>".$ei."</p>";}	// Erkl. Inhalt (speziell)
		echo "\n\t</td>\n</tr>";
	}
}

// Eine zusätzliche Daten-Spalte der Datenbank-Tabelle in eine Zeile der HTML-Tabelle ausgeben (Key|Value|Erklärung)
// Anwendung für die DB-Spalten, die nicht bei jeder Bauwerks-Tabelle vorkommen
function zusatz_spalte($col, $inhalt) { // Spalten-Name, Value
	global $dbg;
	$einh='';
	switch ($col) {	
		case 'objekthoehe':
			$titel='Objekth&ouml;he';
			$einh=' m';
		break;
		case 'breitedesobjekts':
			$titel='Breite des Objekts';
			$einh=' m';
		break;
		case 'bezeichnung':
			$titel='Bezeichnung';
		break;	
		case 'durchfahrtshoehe': // 1
			$titel='Durchfahrtsh&ouml;he';
			$einh=' m';
		break;	
		case 'spannungsebene': // 6
			$titel='Spannungsebene';
			$einh=' KV';
		break;
		case 'produkt': // 7
			$titel='Produkt';
		break;
		case 'kilometerangabe': // 12
			$titel='Kilometerangabe';
			$einh=' KM';
		break;
		default: // noch nicht berücksichtigt
			if ($dbg > 1) {echo "<p class='dbg'>Der Feldname ".$col." ist in function zusatz_spalte noch nicht ber&uuml;cksichtigt.</p>";}
			$titel=$col;
	}
	if ($inhalt != '') {$inhalt.=$einh;}
	tab_zeile($titel, '', $inhalt, '', '');
}

// Eine Zeile der HTML-Tabelle ausgeben, die einen Objektverweis (Relation) enthält
function verweis_zeile($zieltyp, $link, $info) {
	echo "\n<tr>\n\t<td class='li'>".$zieltyp."</td>\n\t<td class='fett'>".$link."</td>\n\t<td>";
	echo "\n\t\t<p class='erkli'>".$info."</p>\n\t</td>\n</tr>";
}

// Ein Relationen-Feld anzeigen
// col = Name der DB-Spalte
// inhalt = gml_id oder Array mit gml_id's
function objektverweis($col, $inhalt) {
	global $gkz, $showkey, $dbg;
//	if ($dbg > 1) {echo "<p>Verweis Typ '".$col."' auf Objekt(e): '".$inhalt."'.</p>";}
	switch ($col) {
		case 'hatdirektunten': // [] In welcher Tabelle kann man dies Objekt finden?
			if (isset($inhalt)) {
				$olist='';
				$arrhdu=explode(",", trim($inhalt, "{}"));
				foreach($arrhdu AS $hdugml) {$olist.=$hdugml."<br>";}
				tab_zeile('Hat direkt Unten', '', $olist, '', 'Verweis auf Objekte unter diesem Bauwerk, Typ unbekannt.');
			}
		break;

		case 'gehoertzu': // Assoziation zu: FeatureType AX_Gebaeude (ax_gebaeude) 0..1'
			if ($inhalt == '') {
				verweis_zeile('Haus', '', 'Das Bauwerk geh&ouml;rt zum Haus');
			} else {
				$link="\n\t\t<a title='geh&ouml;rt zu' href='alkishaus.php?gkz=".$gkz."&amp;gmlid=".$inhalt.LnkStf()
				."'>Haus&nbsp;<img src='ico/Haus.png' width='16' height='16' alt=''></a>";
				verweis_zeile('Haus', $link, 'Das Bauwerk geh&ouml;rt zum Haus');
				bw_gz_lage($inhalt);				
			}
		break;

	// 'istabgeleitetaus';  'traegtbeizu': 'istteilvon': // -> Keine Fälle vorhanden
		default:
			if ($dbg > 1) {echo "<p class='dbg'>Der Feldname ".$col." ist in function 'objektverweis' noch nicht ber&uuml;cksichtigt.</p>";}
	}
}

// S T A R T
ini_set("session.cookie_httponly", 1);
session_start();
$allfld = "n"; $showkey="n"; $nodebug=""; // Var. initalisieren
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
if (!preg_match('#^[0-9]{1,2}$#', $btyp)) {die("Eingabefehler btyp");} // Bauwerks-Typ = Tabelle
if (!preg_match('#^[j|n]{0,1}$#', $showkey)) {die ("Eingabefehler showkey");}
if ($showkey === "j") {$showkey=true;} else {$showkey=false;}
if (!preg_match('#^[j|n]{0,1}$#', $allfld)) {die ("Eingabefehler allfld");}
if ($allfld === "j") {$allefelder=true;} else {$allefelder=false;}
if (!preg_match('#^j{0,1}$#', $nodebug)) {die("Eingabefehler nodebug");}

include "alkis_conf_location.php";
include "alkisfkt.php";

echo <<<END
<!doctype html>
<html lang="de">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>ALKIS Bauwerksdaten</title>
	<link rel="stylesheet" type="text/css" href="alkisauszug.css">
	<link rel="shortcut icon" type="image/x-icon" href="ico/Haus.ico">
</head>
<body>

END;

$erlaubnis = darf_ich(); if ($erlaubnis === 0) { die('<p class="stop1">Abbruch</p></body>'); }
$dbg=$debug;
if ($nodebug === "j") {$dbg=0;}

$con = pg_connect($dbconn." options='--application_name=ALKIS-Auskunft_alkisbauwerk.php'");
if (!$con) echo "\n<p class='err'>Fehler beim Verbinden der DB</p>\n";

// Konstanten für Sortierung und Gruppierung nach Bauwerks-Typen
$btyp_verkehr=1; $btyp_gewaesser=2; $btyp_sonst=3; $btyp_indu=4; $btyp_sport=5;
$btyp_leitg=6; $btyp_trans=7; $btyp_turm=8; $btyp_vorrat=9;
$btyp_hist=10; $btyp_heil=11; $btyp_oeff=12; $btyp_bpkt=13;

/* in ALLEN Bauwerks-Typen folgende Spalten, bisher NICHT verwendet:
    herkunft_source_source_ax_datenerhebung[] herkunft_source_source_scaledenominator[] herkunft_source_source_sourcereferencesystem[]
    herkunft_source_source_sourceextent[]     herkunft_source_source_sourcestep[] */

/* Fallunterscheidung: B a u w e r k s - T y p
Nacheinander werden folgende Abfragen je Objekt ausgeführt:
	1. sqlb: Standard-Spalten, die bei jedem Typ vorhanden sind 
	2. sqlk: Zusätzliche Key-Value-Beziehungen (mit Key und Erklärung)
	3. sqlz: Zusätzliche Spalten, individuell je Objektart (einfache Werte-Darstellung)
	4. sqlr: Relationen zu anderen Objektarten */
$WH=" WHERE b".$btyp.".gml_id = $1 AND b".$btyp.".endet IS NULL;"; // WHERE-Clause (mehrfach je Typ verwendet)
switch ($btyp) {

case $btyp_verkehr:	// 1 - V e r k e h r
	$FR=" FROM ax_bauwerkimverkehrsbereich b1 "; // FROM-Clause (mehrfach je Typ verwendet)
	$btyptitle='Bauwerk im Verkehrsbereich';
	$sqlb="SELECT b1.bauwerksfunktion, k1.beschreibung, k1.dokumentation, b1.name, b1.statement, GeometryType(b1.wkb_geometry) as bgeotyp, round(st_area(b1.wkb_geometry)::numeric,2) AS flae"
	.$FR."LEFT JOIN ax_bauwerksfunktion_bauwerkimverkehrsbereich k1 ON b1.bauwerksfunktion=k1.wert".$WH;
	$sqlk="SELECT b1.zustand, k1.beschreibung, k1.dokumentation".$FR."LEFT JOIN ax_zustand_bauwerkimverkehrsbereich k1 ON b1.zustand=k1.wert".$WH;
	$sqlz="SELECT b1.bezeichnung, b1.breitedesobjekts, b1.durchfahrtshoehe".$FR.$WH;
	$sqlr="SELECT b1.hatdirektunten".$FR.$WH;
break;

case $btyp_gewaesser:	// 2 - G e w ä s s e r
	$FR=" FROM ax_bauwerkimgewaesserbereich b2 ";
	$btyptitle='Bauwerk im Gewässerbereich';
	$sqlb="SELECT b2.bauwerksfunktion, ug.beschreibung, ug.dokumentation, b2.name, b2.statement, GeometryType(b2.wkb_geometry) as bgeotyp, round(st_area(b2.wkb_geometry)::numeric,2) AS flae"
	.$FR."LEFT JOIN ax_bauwerksfunktion_bauwerkimgewaesserbereich ug ON b2.bauwerksfunktion=ug.wert".$WH;
	$sqlk="SELECT b2.zustand, k2.beschreibung, k2.dokumentation".$FR."LEFT JOIN ax_zustand_bauwerkimgewaesserbereich k2 ON b2.zustand=k2.wert".$WH;
	$sqlz="SELECT b2.bezeichnung".$FR.$WH;
	$sqlr="SELECT b2.hatdirektunten".$FR.$WH;
break;

case $btyp_sonst:	// 3 - S o n s t i g e  Bauwerke
	$FR=" FROM ax_sonstigesbauwerkodersonstigeeinrichtung b3 ";
	$btyptitle='Sonstiges Bauwerk oder sonstige Einrichtung';
	$sqlb="SELECT b3.bauwerksfunktion, k3.beschreibung, k3.dokumentation, b3.name, b3.statement, GeometryType(b3.wkb_geometry) as bgeotyp, round(st_area(b3.wkb_geometry)::numeric,2) AS flae"
	.$FR."LEFT JOIN ax_bauwerksfunktion_sonstigesbauwerkodersonstigeeinrichtun k3 ON b3.bauwerksfunktion=k3.wert".$WH;
	$sqlk="SELECT b3.funktion, k3.beschreibung, k3.dokumentation, b3.hydrologischesmerkmal, hm.beschreibung AS hmbeschr, hm.dokumentation AS hmdoku"
	.$FR."LEFT JOIN ax_funktion_bauwerk k3 ON b3.funktion=k3.wert "
	." LEFT JOIN ax_hydrologischesmerkmal_sonstigesbauwerkodersonstigeeinri hm ON b3.hydrologischesmerkmal=hm.wert".$WH;
	$sqlz="SELECT b3.bezeichnung, b3.objekthoehe".$FR.$WH;
 	$sqlr="SELECT b3.hatdirektunten, b3.gehoertzu".$FR.$WH;
break;

case $btyp_indu:	// 4 - Bauwerk oder Anlage für  I n d u s t r i e  und Gewerbe
	$FR=" FROM ax_bauwerkoderanlagefuerindustrieundgewerbe b4 ";
	$btyptitle="Bauwerk oder Anlage für Industrie und Gewerbe";
	$sqlb="SELECT b4.bauwerksfunktion, k4.beschreibung, k4.dokumentation, b4.name, b4.statement, GeometryType(b4.wkb_geometry) as bgeotyp, round(st_area(b4.wkb_geometry)::numeric,2) AS flae"
	.$FR."LEFT JOIN ax_bauwerksfunktion_bauwerkoderanlagefuerindustrieundgewer k4 ON b4.bauwerksfunktion=k4.wert".$WH;
	$sqlk="SELECT b4.zustand, k4.beschreibung, k4.dokumentation"
	.$FR."LEFT JOIN ax_zustand_bauwerkoderanlagefuerindustrieundgewerbe k4 ON b4.zustand=k4.wert".$WH;
	$sqlz="SELECT b4.bezeichnung, b4.objekthoehe".$FR.$WH;
 	$sqlr="SELECT b4.hatdirektunten".$FR.$WH;
break;

case $btyp_sport:	// 5 - Bauwerk oder Anlage für  S p o r t , Freizeit und Erholung
	$FR=" FROM ax_bauwerkoderanlagefuersportfreizeitunderholung b5 ";
	$btyptitle="Bauwerk oder Anlage für Sport, Freizeit und Erholung";
	$sqlb="SELECT b5.bauwerksfunktion, k5.beschreibung, k5.dokumentation, b5.name, b5.statement, GeometryType(b5.wkb_geometry) as bgeotyp, round(st_area(b5.wkb_geometry)::numeric,2) AS flae"
	.$FR."LEFT JOIN ax_bauwerksfunktion_bauwerkoderanlagefuersportfreizeitunde k5 ON b5.bauwerksfunktion=k5.wert".$WH;
	$sqlk="SELECT b5.sportart, k5.beschreibung, k5.dokumentation"
	.$FR."LEFT JOIN ax_sportart_bauwerkoderanlagefuersportfreizeitunderholung k5 ON b5.sportart=k5.wert".$WH;
	$sqlz="SELECT b5.breitedesobjekts".$FR.$WH;
 	$sqlr="SELECT b5.hatdirektunten".$FR.$WH;
break;

case $btyp_leitg:	// 6 - L e i t u n g
	$FR=" FROM ax_leitung b6 ";
	$btyptitle="Leitung";
	$sqlb="SELECT b6.bauwerksfunktion, k6.beschreibung, k6.dokumentation, b6.name, b6.statement, GeometryType(b6.wkb_geometry) as bgeotyp, round(st_area(b6.wkb_geometry)::numeric,2) AS flae"
	.$FR."LEFT JOIN ax_bauwerksfunktion_leitung k6 ON b6.bauwerksfunktion=k6.wert".$WH;	
	$sqlk="";
	$sqlz="SELECT b6.spannungsebene".$FR.$WH;
 	$sqlr="SELECT b6.hatdirektunten".$FR.$WH;
break;

case $btyp_trans:	// 7 - T r a n s p o r t a n l a g e
	$FR=" FROM ax_transportanlage b7 ";
	$btyptitle="Transportanlage";
	$sqlb="SELECT b7.bauwerksfunktion, k7.beschreibung, k7.dokumentation, b7.statement, GeometryType(b7.wkb_geometry) as bgeotyp, round(st_area(b7.wkb_geometry)::numeric,2) AS flae"
	.$FR."LEFT JOIN ax_bauwerksfunktion_transportanlage k7 ON b7.bauwerksfunktion=k7.wert".$WH;
	$sqlk="SELECT b7.lagezurerdoberflaeche, k7.beschreibung, k7.dokumentation"
	.$FR."LEFT JOIN ax_lagezurerdoberflaeche_transportanlage k7 ON b7.lagezurerdoberflaeche=k7.wert".$WH;
	$sqlz="SELECT b7.produkt".$FR.$WH;
 	$sqlr="SELECT b7.hatdirektunten".$FR.$WH;
break;

case $btyp_turm:	// 8 - T u r m  (Sonderfall Array)
	$FR=" FROM ax_turm b8 ";
	$btyptitle="Turm";
	$sqlb="SELECT k8.wert AS bauwerksfunktion, k8.beschreibung, k8.dokumentation, b8.name, b8.statement, GeometryType(b8.wkb_geometry) as bgeotyp, round(st_area(b8.wkb_geometry)::numeric,2) AS flae"
	.$FR."LEFT JOIN ax_bauwerksfunktion_turm k8 ON k8.wert =ANY(b8.bauwerksfunktion)".$WH;
	$sqlk="SELECT b8.zustand, k8.beschreibung, k8.dokumentation"
	.$FR."LEFT JOIN ax_zustand_turm k8 ON b8.zustand=k8.wert".$WH;
	$sqlz="SELECT b8.objekthoehe".$FR.$WH;
 	$sqlr="SELECT b8.hatdirektunten, b8.zeigtauf".$FR.$WH;
break;

case $btyp_vorrat:	// 9 -  V o r r a t s b e h ä l t e r ,  S p e i c h e r b a u w e r k
	$FR=" FROM ax_vorratsbehaelterspeicherbauwerk b9 ";
	$btyptitle="Vorratsbehälter, Speicherbauwerk";
	$sqlb="SELECT b9.bauwerksfunktion, k9.beschreibung, k9.dokumentation, b9.name, b9.statement, GeometryType(b9.wkb_geometry) as bgeotyp, round(st_area(b9.wkb_geometry)::numeric,2) AS flae"
	.$FR."LEFT JOIN ax_bauwerksfunktion_vorratsbehaelterspeicherbauwerk k9 ON b9.bauwerksfunktion=k9.wert".$WH;
	$sqlk="SELECT b9.lagezurerdoberflaeche, k9.beschreibung, k9.dokumentation, 
	 b9.speicherinhalt, sp9.beschreibung AS spbes, sp9.dokumentation AS spdok"
	.$FR."LEFT JOIN ax_lagezurerdoberflaeche_vorratsbehaelterspeicherbauwerk k9 ON b9.lagezurerdoberflaeche=k9.wert 
 LEFT JOIN ax_speicherinhalt_vorratsbehaelterspeicherbauwerk sp9 ON b9.speicherinhalt=sp9.wert".$WH;
	$sqlz="SELECT b9.objekthoehe".$FR.$WH;
 	$sqlr="SELECT b9.hatdirektunten".$FR.$WH;
break;

case $btyp_hist: // 10 - H i s t o r i s c h e s  Bauwerk oder historische Einrichtung
	$FR=" FROM ax_historischesbauwerkoderhistorischeeinrichtung b10 ";
	$btyptitle="Historisches Bauwerk oder historische Einrichtung";
	$sqlb="SELECT b10.name, b10.statement, GeometryType(b10.wkb_geometry) as bgeotyp, round(st_area(b10.wkb_geometry)::numeric,2) AS flae".$FR.$WH;
	$sqlk="SELECT b10.archaeologischertyp, k10.beschreibung, k10.dokumentation"
	.$FR."LEFT JOIN ax_archaeologischertyp_historischesbauwerkoderhistorischee k10 ON b10.archaeologischertyp=k10.wert".$WH;
	$sqlz="";
 	$sqlr="SELECT b10.hatdirektunten".$FR.$WH;
break;

case $btyp_heil: // 11 - H e i l q u e l l e ,  G a s q u e l l e
	$FR=" FROM ax_heilquellegasquelle b11 ";
	$btyptitle="Heilquelle, Gasquelle";
	$sqlb="SELECT b11.name, b11.statement, GeometryType(b11.wkb_geometry) as bgeotyp, round(st_area(b11.wkb_geometry)::numeric,2) AS flae".$FR.$WH;
	$sqlk="SELECT b11.art, k11.beschreibung, k11.dokumentation, "
	."b11.hydrologischesmerkmal, hm.beschreibung AS hmbes, hm.dokumentation AS hmdok".$FR
	."LEFT JOIN ax_art_heilquellegasquelle k11 ON b11.art=k11.wert "
	."LEFT JOIN ax_hydrologischesmerkmal_heilquellegasquelle hm ON b11.hydrologischesmerkmal=hm.wert".$WH;
	$sqlz="";
 	$sqlr="SELECT b11.hatdirektunten ".$FR.$WH;
break;

case $btyp_oeff: // 12 - Einrichtung in  ö f f e n t l i c h e n  Bereichen
	$FR=" FROM ax_einrichtunginoeffentlichenbereichen b12 ";
	$btyptitle="Einrichtung in &ouml;ffentlichen Bereichen";
	$sqlb="SELECT b12.statement, GeometryType(b12.wkb_geometry) as bgeotyp, round(st_area(b12.wkb_geometry)::numeric,2) AS flae".$FR.$WH;
	$sqlk="SELECT b12.art, k12.beschreibung, k12.dokumentation".$FR
	."LEFT JOIN ax_art_einrichtunginoeffentlichenbereichen k12 ON b12.art=k12.wert".$WH;
	$sqlz="SELECT b12.kilometerangabe".$FR.$WH;
 	$sqlr="SELECT b12.hatdirektunten ".$FR.$WH;
break;
/*  +++ B A U S T E L L E +++
case $btyp_bpkt: // 13 - Besonderer Bauwerkspunkt (ohne Geom.)
	$FR=" FROM ax_besondererbauwerkspunkt b13 ";
	$btyptitle="Besonderer Bauwerkspunkt";
//  punktkennung, sonstigeeigenschaft, zustaendigestelle_land, zustaendigestelle_stelle

break;

CREATE TABLE IF NOT EXISTS public.ax_besondererbauwerkspunkt
(   ...
	punktkennung character varying, -- immer gefüllt, 
	   z.B. '324825754002450'
	         rrhhrrhhAnnnnnn	
    sonstigeeigenschaft character varying[],
    zustaendigestelle_land character varying,
    zustaendigestelle_stelle character varying,


SELECT b.gml_id, p.gml_id 
FROM ax_besondererbauwerkspunkt b
JOIN ax_punktortag p ON b.gml_id = any(p.istteilvon)
LIMIT 100;

Das ZUSO besteht aus einem 'PunktortAG' und/oder aus einem oder mehreren 'PunktortAU'.

Der 'Besondere Bauwerkspunkt' und der ihm zugeordnete 'Punktort' mit der Attributart 'Liegenschaftskarte' und der Werteart TRUE erhält 
den Raumbezug durch einen Punkt der Fläche oder der Linie, die zur Vermittlung des Raumbezuges des entsprechenden Bauwerks 
oder der Einrichtung beiträgt.


 'PunktortAG' ist ein Punktort mit redundanzfreier Geometrie (Besonderer Gebäudepunkt, Besonderer Bauwerkspunkt) innerhalb eines Geometriethemas.
    istteilvon[]


 'PunktortAU' ist ein Punktort mit unabhängiger Geometrie ohne Zugehörigkeit zu einem Geometriethema. Er kann zu ZUSOs der folgenden Objektarten gehören: Grenzpunkt, Besonderer Gebäudepunkt, Besonderer Bauwerkspunkt, Aufnahmepunkt, Sicherungspunkt, Sonstiger Vermessungspunkt, Besonderer topographischer Punkt, Lagefestpunkt, Höhenfestpunkt, Schwerefestpunkt, Referenzstationspunkt.

 'PunktortTA' ist ein Punktort, der in der Flurstücksgrenze liegt und einen Grenzpunkt verortet.
  
	
Schlüssel:
	ax_art_punktkennung

pg_dump  -d alkis0150 -s -x -O -F p -f /home/b600352/ALKIS-Schema.sql

 */

default:
	die('<p class="stop1">Falscher Bauwerkstyp.</p></body>');
break;
}

// 1. sqlb: Standard-Spalten, die bei (fast) jedem Typ vorhanden sind
$v=array($gmlid);
$resb=pg_prepare($con, "", $sqlb);
$resb=pg_execute($con, "", $v);
if (!$resb) {
	echo "\n<p class='err'>Fehler bei Bauwerksdaten Standardfelder.</p>";
	if ($dbg > 0) {
		echo "\n<p class='dbg'>Fehler:".pg_result_error($resb)."</p>";
		if ($dbg > 2) {echo "\n<p class='err'>SQL=<br>".str_replace("$1","'".$gmlid."'",$sqlb)."'</p>";}
	}
} else {
	if ($dbg > 0) {
		$zeianz=pg_num_rows($resb);
		if ($zeianz > 1){
			echo "\n<p class='err'>Die Abfrage liefert mehr als ein (".$zeianz.") Bauwerk! (Standardfelder)</p>";
			if ($dbg > 2) {echo "\n<p class='dbg'>SQL=<br>".htmlentities(str_replace("$1","'".$gmlid."'",$sqlb), ENT_QUOTES, "UTF-8")."</p>";}
		}
	}
	if (!$rowb = pg_fetch_assoc($resb)) {
		echo "\n<p class='err'>Fehler! Kein Treffer f&uuml;r gml_id=".$gmlid."</p>";
		die('<p class="stop1">Abbruch</p></body>'); // Das würde sich sonst 2x wiederholen
	} else { // Treffer
		// Seitenkopf
		echo "<p class='balken bauwerk".$btyp."'>ALKIS Bauwerk ".$btyp." - ".$gmlid."&nbsp;</p>" // Balken, Farbe wie WMS
		."\n<h2>".$btyptitle."</h2>"
		."\n<p class='nwlink noprint'>" // Umschalter: auch leere Felder
		."Umschalten: <a class='nwlink' href='".selbstverlinkung()."?gkz=".$gkz."&amp;btyp=".$btyp."&amp;gmlid=".$gmlid.LnkStf();
		if ($allefelder) {
			echo "&amp;allfld=n'>nur Felder mit Inhalt";
		} else {
			echo "&amp;allfld=j'>auch leere Felder";
		}
		echo "</a></p>";

		echo "\n<table class='geb'>"
		."\n<tr>"
			."\n\t<td class='head' title=''>Attribut</td>"
			."\n\t<td class='head mittelspalte' title=''>Wert</td>"
			."\n\t<td class='head' title=''>"
				."\n\t\t<p class='erklk'>Erkl&auml;rung zum Attribut</p>"
				."\n\t\t<p class='erkli'>Erkl&auml;rung zum Inhalt</p>"
			."\n\t</td>"
		."\n</tr>";

		if (isset($rowb["bauwerksfunktion"])) { // nicht immer vorhanden
			tab_zeile('Bauwerksfunktion', $rowb["bauwerksfunktion"], htmlentities($rowb["beschreibung"], ENT_QUOTES, "UTF-8"), '', htmlentities($rowb["dokumentation"], ENT_QUOTES, "UTF-8"));
		}

		if (isset($rowb["name"])) {
			tab_zeile('Name', '', htmlentities($rowb["name"], ENT_QUOTES, "UTF-8"), '', '');
		}

		if (isset($rowb["statement"])) {
			tab_zeile('Statement', '', htmlentities($rowb["statement"], ENT_QUOTES, "UTF-8"), '', '');
		}

		// G e o m e t r i e  und Fläche
		if (isset($rowb["bgeotyp"])) {
			$geotyp=$rowb["bgeotyp"];
			switch ($geotyp) {
				case "POINT":
					$geodeutsch=$geotyp." bedeutet 'Punkt', einzelne Koordinate.";
				break;
				case "LINESTRING":
					$geodeutsch=$geotyp." bedeutet 'Linie'.";
				break;
				case "POLYGON":
					$geodeutsch=$geotyp." bedeutet eine einzelne 'Fl&auml;che'.";
				break;
				case "MULTIPOLYGON":
					$geodeutsch=$geotyp." bedeutet eine 'Fl&auml;che', die aus mehreren Teilen bestehen kann oder die Aussparungen haben kann.";	
				break;
				default: $geodeutsch="";
			}
			tab_zeile('Geometrietyp', '', $geotyp, '', $geodeutsch);

			if ($geotyp == "POLYGON" or $geotyp == "MULTIPOLYGON") {
				$flaeche=$rowb["flae"]." m&#178;";
				tab_zeile('Fl&auml;che', '', $flaeche, '', "Die 'Fl&auml;che' des Bauwerks wird aus der Geometrie berechnet, aber nur bei POLYGON.");
			}
		}
	}
	pg_free_result($resb);
}

// 2. sqlk:  Zusätzliche Key-Value-Beziehungen
// Andere Art der Darstellung als die schlichten Zusatzfelder, Key optional anzeigen, Erklärung zum Wert aus Schlüsseltabelle
if ($sqlk != '') {
	$v=array($gmlid);
	$resk=pg_prepare($con, "", $sqlk);
	$resk=pg_execute($con, "", $v);	
	if (!$resk) {
		echo "\n<p class='err'>Fehler bei Schl&uuml;sseltabelle ".$btyp."</p>";
		if ($dbg > 0) {
			echo "<p class='dbg'>Fehler:".pg_result_error($resk)."</p>";
			if ($dbg > 2) {echo "\n<p class='err'>SQL=<br>".str_replace("$1","'".$gmlid."'",$sqlk)."'</p>";}
		}
	} else {
		if ($dbg > 0) {
			$zeianz=pg_num_rows($resk);
			if ($zeianz > 1){
				echo "\n<p class='err'>Die Abfrage liefert mehr als ein (".$zeianz.") Bauwerk! (Key-Value)</p>";
				if ($dbg > 2) {echo "\n<p class='dbg'>SQL=<br>".htmlentities(str_replace("$1","'".$gmlid."'",$sqlk), ENT_QUOTES, "UTF-8")."</p>";}
			}
		}
		if (!$rowk = pg_fetch_array($resk)) {
			echo "\n<p class='err'>Fehler! Kein Treffer f&uuml;r gml_id=".$gmlid."</p>";
		} else {
		// Treffer
			if (is_null($rowk["beschreibung"])) {
				$bes="";
			} else {
				$bes=htmlentities($rowk["beschreibung"], ENT_QUOTES, "UTF-8");
			}
			if (is_null($rowk["dokumentation"])) {
				$dok="";
			} else {
				$dok=htmlentities($rowk["dokumentation"], ENT_QUOTES, "UTF-8");
			}
			switch ($btyp) {  // individuell je Typ
			case $btyp_verkehr: // 1
				tab_zeile('Zustand', $rowk["zustand"], $bes, '', $dok);
			break;
			case $btyp_gewaesser: // 2
				tab_zeile('Zustand', $rowk["zustand"], $bes, '', $dok);
			break;
			case $btyp_sonst: // 3
				tab_zeile('Funktion', $rowk["funktion"], $bes, '', $dok);
				if (is_null($rowk["hmbeschr"])) {
					$hmbeschr="";
				} else {
					$hmbeschr=htmlentities($rowk["hmbeschr"], ENT_QUOTES, "UTF-8");
				}
				if (is_null($rowk["hmdoku"])) {
					$hmdoku="";
				} else {
					$hmdoku=htmlentities($rowk["hmdoku"], ENT_QUOTES, "UTF-8");
				}
				tab_zeile('Hydrologisches Merkmal', $rowk["hydrologischesmerkmal"], $hmbeschr, '', $hmdoku);
			break;
			case $btyp_indu: // 4
				tab_zeile('Zustand', $rowk["zustand"], $bes, '', $dok);
			break;
			case $btyp_sport: // 5
				tab_zeile('Sportart', $rowk["sportart"], $bes, '', $dok);
			break;
			case $btyp_trans: // 7
				tab_zeile('Lage zur Erdoberfl&auml;che', $rowk["lagezurerdoberflaeche"], $bes, '', $dok);
			break;
			case $btyp_turm: // 8
				tab_zeile('Zustand', $rowk["zustand"], $bes, '', $dok);
			break;
			case $btyp_vorrat: // 9
				tab_zeile('Lage zur Erdoberfl&auml;che', $rowk["lagezurerdoberflaeche"], $bes, '', $dok);
				tab_zeile('Speicherinhalt', $rowk["speicherinhalt"], htmlentities($rowk["spbes"], ENT_QUOTES, "UTF-8"), '', htmlentities($rowk["spdok"], ENT_QUOTES, "UTF-8"));
			break;
			case $btyp_hist: // 10
				tab_zeile('Arch&auml;ologischer Typ', $rowk["archaeologischertyp"], $bes, '', $dok);
			break;
			case $btyp_heil: // 11
				tab_zeile('Art', $rowk["art"], $bes, '', $dok);
				tab_zeile('Hydrologisches Merkmal', $rowk["hydrologischesmerkmal"], htmlentities($rowk["hmbes"], ENT_QUOTES, "UTF-8"), '', htmlentities($rowk["hmdok"], ENT_QUOTES, "UTF-8"));
			break;
			case $btyp_oeff: // 12
				tab_zeile('Art', $rowk["art"], $bes, '', $dok);
			break;
//			case $btyp_bpkt: // 13
//			break;
			}
		}
		pg_free_result($resk);
	}
}

// 3. sqlz: Individuelle Z u s a t z - Spalten je Bauwerks-Art
// einfache Werte-Anzeige, ohne Schlüsseltabelle
if ($sqlz != '') {
	$v=array($gmlid);
	$resz=pg_prepare($con, "", $sqlz);
	$resz=pg_execute($con, "", $v);
	if (!$resz) {
		echo "\n<p class='err'>Fehler bei Bauwerk Relation.</p>";
		if ($dbg > 0) {
			echo "<p class='dbg'>Fehler:".pg_result_error($resz)."</p>";
			if ($dbg > 2) {echo "\n<p class='err'>SQL=<br>".str_replace("$1","'".$gmlid."'",$sqlz)."'</p>";}
		}
	} else {
		if ($dbg > 0) {
			$zeianz=pg_num_rows($resz);
			if ($zeianz > 1){
				echo "\n<p class='err'>Die Abfrage liefert mehr als ein (".$zeianz.") Bauwerk! (Zusatz-Spalten)</p>";
				if ($dbg > 2) {echo "\n<p class='dbg'>SQL=<br>".htmlentities(str_replace("$1","'".$gmlid."'",$sqlz), ENT_QUOTES, "UTF-8")."</p>";}
			}
		}
		if (!$rowz = pg_fetch_array($resz)) {
			echo "\n<p class='err'>Fehler! Kein Treffer f&uuml;r gml_id=".$gmlid."</p>";
		} else {
			// Die Spalten im Row abarbeiten
			$anzcol=pg_num_fields($resz);
			for ($i = 0; $i < $anzcol; $i++) {
				$k=pg_field_name($resz, $i);
				$v=$rowz[$i];
				if ($v != "" OR $allefelder) {
					zusatz_spalte($k, $v);
				}
			}
		}	
		pg_free_result($resz);
	}
}

// 4. sqlr: R e l a t i o n e n  zu anderen Objektarten
if ($sqlr != '') {
	$v=array($gmlid);
	$resr=pg_prepare($con, "", $sqlr);
	$resr=pg_execute($con, "", $v);
	if (!$resr) {
		echo "\n<p class='err'>Fehler bei Bauwerksdaten Zusatzfelder.</p>";
		if ($dbg > 0) {
			echo "<p class='dbg'>Fehler:".pg_result_error($resr)."</p>";
			if ($dbg > 2) {echo "\n<p class='err'>SQL=<br>".str_replace("$1","'".$gmlid."'",$sqlr)."'</p>";}
		}
	} else {
		if ($dbg > 0) {
			$zeianz=pg_num_rows($resr);
			if ($zeianz > 1){
				echo "\n<p class='err'>Die Abfrage liefert mehr als ein (".$zeianz.") Bauwerk! (Relationen)</p>";
				if ($dbg > 2) {echo "\n<p class='dbg'>SQL=<br>".htmlentities(str_replace("$1","'".$gmlid."'",$sqlr), ENT_QUOTES, "UTF-8")."</p>";}
			}
		}
		if (!$rowr = pg_fetch_array($resr)) {
			echo "\n<p class='err'>Fehler! Kein Treffer f&uuml;r gml_id=".$gmlid."</p>";
		} else { // Die Spalten im Row abarbeiten
			$anzcol=pg_num_fields($resr);
			for ($i = 0; $i < $anzcol; $i++) {
				$k=pg_field_name($resr, $i);
				$v=$rowr[$i]; // Array mit gml_id anderer Objekte
				if ($v != "" OR $allefelder) {
					objektverweis($k, $v);
				}
			}
		}	
		pg_free_result($resr);
	}
}

echo "\n</table>\n";
echo "<div class='buttonbereich noprint'>\n<hr>\n\t<a title='zur&uuml;ck' href='javascript:history.back()'><img src='ico/zurueck.png' width='16' height='16' alt='zur&uuml;ck'></a>&nbsp;";
if ($PrntBtn==true){echo "\n\t<a title='Drucken' href='javascript:window.print()'><img src='ico/print.png' width='16' height='16' alt='Drucken'></a>&nbsp;";}
echo "\n</div>";

footer($gmlid, selbstverlinkung()."?", "&amp;btyp=".$btyp);
?>
</body>
</html>

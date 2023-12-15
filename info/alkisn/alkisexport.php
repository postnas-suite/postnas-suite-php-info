<?php
/*	alkisexport.php

	ALKIS-Auskunft
	Author: Frank Jäger, Kommunales Rechenzentrum Minden-Ravensberg/Lippe (Lemgo)
	
	CSV-Export von ALKIS-Daten zu einem Flurstueck, Grundbuch, Eigentümer oder Straße.
	Es wird eine GML-ID übergeben.
	Es wird ein gespeicherter View verwendet, der nach der gml_id gefiltert wird. 
	Der View verkettet Flurstueck - Buchungsstelle - Grundbuch - Eigentümer
	Die Lagebezeichnung des Flurstücks wird in ein Feld komprimiert.
	Parameter Beispiele: 
		?gkz=mandant&tabtyp=flurstueck/grundbuch/buchung/person/strasse&gmlid=DE...  Standard
		?gkz=270&tabtyp=gemarkung&gemarkung=2662         Sonderfall ganze Gemarkung
		?gkz=270&gemarkung=2662
		?gkz=mandant&tabtyp=strasse&haus=m&gmlid=DE...   Filter &haus=m/o = mit oder ohne Hausnummer
		?gkz=mandant&tabtyp=strasse&haus=o               bei Strasse auch ohne gmlid zulässig - nicht verwenden wenn aus NBA unscharf geladen
		?gkz240,tabtyp=flstliste&gmlliste=DE...,DE....
	Beispiele für Fehler:
		?gkz=270&tabtyp=gemarkung&gmlid=2662
		?gkz=270&tabtyp=flurstueck&gemarkung=2662
		?gkz=270&tabtyp=flurstueck
		?gkz=270&gmlid=2662

	Version:
	--------
	2016-02-23	Version für norGIS-ALKIS-Import
	....
	2018-05-03 Aufruf aus neuem Grundstücksnachweis: tabtyp='buchung', angepasster View "exp_csv" notwendig
	2018-10-16 Neuer Aufruf-Typ aus der räumlichen Selektion, &tabtyp=flstliste&prefix=DENW15&gmlliste=AL...,AL....
	2020-12-16 Input-Validation und Strict Comparisation (===), Berechtigungsprüfung vorübergehend deaktiviert
	2021-12-01 Client-Encoding
	2022-07-05 PHP 8.1: Connection verwenden bei "pg_prepare" und "pg_execute", keine NULL-Werte in String-Functions verwenden

	ToDo:.
	- In den gespeicherten Views "exp_csv" und "exp_csv_str" den Fall Kataster-Amts-Mix berücksichtigen.
	- Abruf Flurstück sollte auch mit "fskennz" (ggg-ff-zzz/nn) statt "gml-id" möglich sein
	- In Dateiname den Straßennamen statt der gmlid verwenden? (Umlaute?)
	- in alkislage.php für den Typ "ohne Haunummer" den Export mit strasse und haus=o verlinken
*/

function lage_zum_fs($gmlid) {
	// Zu einem Flurstück die Lagebezeichnungen (mit Hausnummer) so aufbereiten, 
	// dass ggf. mehrere Lagebezeichnungen in eine Zelle der Tabelle passen.
	// FS >westAuf> Lage >> Katalog
	global $con;
	$sql ="SELECT DISTINCT s.bezeichnung, l.hausnummer "
	."FROM ax_flurstueck f JOIN ax_lagebezeichnungmithausnummer l ON l.gml_id=ANY(f.weistauf) "
	."JOIN ax_lagebezeichnungkatalogeintrag s ON l.kreis=s.kreis AND l.gemeinde=s.gemeinde AND l.lage=s.lage "
	."WHERE f.gml_id= $1 AND f.endet IS NULL AND l.endet IS NULL AND s.endet IS NULL ORDER BY s.bezeichnung, l.hausnummer;";

	$v=array($gmlid);
	$res=pg_prepare($con, "", $sql);
	$res=pg_execute($con, "", $v);
	if (!$res) {return "** Fehler bei Lagebezeichnung **";}
	$j=0;
	$lagehsnr="";
	$salt="";
	while($row = pg_fetch_assoc($res)) {
		if ($j > 0) {$lagehsnr.=", ";}
		$sneu=$row["bezeichnung"];
		if ($sneu === $salt) { // gleiche Str.
			$lagehsnr.=$row["hausnummer"]; // HsNr dran hängen
		} else { // Name UND HsNr dran hängen
			$lagehsnr.=$sneu." ".$row["hausnummer"];
		}
		$salt=$sneu; // Name f. nächste Runde
		$j++;
	}
	pg_free_result($res);
	return($lagehsnr);
}

// START
$tabtyp='';  $haus=''; // Var. init.
$cntget = extract($_GET); // Parameter in Variable umwandeln

// strikte Validierung aller Parameter
if (isset($gmlid)) {
	if ( !preg_match('#^[0-9A-Za-z]{16}$#', $gmlid)) {die("Eingabefehler gmlid");}
} else {
	$gmlid="";
}

// FS-Limit? x (16+1) = 
if (isset($gmlliste)) {
	if (!preg_match("#^[0-9A-Za-z,]{16,2000}$#", $gmlliste)) {
		die("Eingabefehler gmlliste");
	}
}

if (isset($gkz)) {
	if (!preg_match('#^[0-9]{3}$#', $gkz)) {die("Eingabefehler gkz");}
} else {
	die("Fehlender Parameter");
}
if (isset($gemarkung)) {
	if (!preg_match('#^[0-9]{4}$#', $gemarkung)) {die("Eingabefehler gemarkung");}
} else {
	$gemarkung='';
}
if (!preg_match('#^[m|o]{0,1}$#', $haus)) {die("Eingabefehler haus");}
if (!preg_match('#^[a-z]{0,10}$#', $tabtyp)) {die("Eingabefehler tabtyp");}

include "alkis_conf_location.php";
include "alkisfkt.php";

if ($tabtyp === '') { // Parameter (-kombinationen) prüfen
	if ($gemarkung != '') { // Dieser Parameters bestimmt auch eindeutig den $tabtyp
		$tabtyp = 'gemarkung';
	} else {  // Bei "gmlid" MUSS man zwingend die Tabelle dazu nennen
		$err="\nFehler: Art des Suchfilters nicht angeben.";
		exit ($err);
	}
} elseif ($tabtyp === 'gemarkung') {
	if ($gemarkung === '') {
		$err="\nFehler: Gemarkungsnummer nicht angeben.";
		exit ($err);
	}
}

if ($gmlid != '') { // Angabe von gmlid ist der Normalfall, das passt für fast jeden tabtyp
	if ($tabtyp === 'strasse' and $haus != '') { // den Zusatzfilter m/o im Dateinamen dokumentieren
		if ($haus === 'm') {
			$filename='alkis_'.$tabtyp.'_'.$gmlid.'_mit_hsnr.csv';
		} else {  // = o
			$filename='alkis_'.$tabtyp.'_'.$gmlid.'_ohne_hsnr.csv.csv';
		}
	} else {
		$filename='alkis_'.$tabtyp.'_'.$gmlid.'.csv';
	}
} else { // Oh Oh! Keine gmlid! - Alternativen?
	if ($gemarkung != '') { // Sonderfall 1 - Gemarkungsnummer statt gmlid als Filter
		if ($tabtyp != 'gemarkung') {
			$err = "Fehler: Falsche Kombination Parameter tabtyp='".$tabtyp."' mit Wert fuer Gemarkungsnummer.";
			echo "\n".$err; exit ($err);
		}
		$filename='alkis_'.$tabtyp.'_'.$gemarkung.'.csv';

/* 	// $gmlid zu strasse ist noch notwendig solange kein Filter auf "Gemeinde" verwendet wird.
	} elseif ($haus === 'm' or $haus === 'o') { // Sonderfall 2 - alle mit/ohne Hausnummer, nur über View "exp_csv_str" möglich
		if ($tabtyp != 'strasse') {
			$err="\nFehler: Falsche Kombination Parameter tabtyp='".$tabtyp."' mit Wert fuer Haus.";
			exit ($err);
		}
		if ($haus === 'm') { // den Zusatzfilter m/o im Dateinamen dokumentieren
			$filename='alkis_'.$tabtyp.'_mit_hsnr.csv';
		} else {
			$filename='alkis_'.$tabtyp.'_ohne_hsnr.csv';
		}
*/
	} elseif ($gmlliste != '') { // Sonderfall 3 - Flurstücke aus räumlicher Selection
		if ($tabtyp != 'flstliste') {
			$err = "Fehler: Falsche Kombination Parameter tabtyp='".$tabtyp."' mit Liste der GML-ID.";
			echo "\n".$err; exit ($err);
		}
		if (!isset($prefix) or !preg_match("#^[A-Z0-9,]{6}$#", $prefix)) {
			die("Eingabefehler prefix");
		}
		$filename='alkis_gebiet.csv'; // Räumliche Selection

	} else {
		$err="\nFehler: Kein passender Wert fuer die Suche angegeben.";
		exit ($err);
	}
}

// DOWNLOAD der CSV-Datei vorbereiten (statt HTML-Ausgabe)
header('Content-type: application/octet-stream');
header('Content-Disposition: attachment; filename="'.$filename.'"');

// CSV-Ausgabe: Kopfzeile mit Feldnamen
echo "FS-Kennzeichen;GmkgNr;Gemarkung;Flur;Flurstueck;Flaeche;Adressen;GB-BezNr;GB-Bezirk;GB-Blatt;BVNR;Anteil_am_FS;Buchungsart;Namensnummer;AnteilDerPerson;RechtsGemeinschaft;Person;GebDatum;Anschrift;Anteil(berechnet)";

// Datenbank-Verbindung
$con = pg_connect($dbconn." options='--application_name=ALKIS-Auskunft_alkisexport.php'");
if (!$con) {
	$err= "Fehler beim Verbinden der DB";
	echo "\n".$err; exit($err);
}
//pg_set_client_encoding($con, 'LATIN1'); // Für Excel kein UTF8
pg_set_client_encoding($con, 'WIN1252'); // Auch Buchstaben z.B. mit "CARON", wie ž Ž š Š

//$viewname="exp_csv"; // Standard-View, in der DB gespeichert
$v=array($gmlid); // Standard-Filter-Feld

// Der Parameter "Tabellentyp" bestimmt den Namen des Filter-Feldes aus dem View "exp_csv".
switch ($tabtyp) { // zulaessige Werte fuer &tabtyp=

	case 'flurstueck': // ax_flurstueck.gml_id
		$sql="SELECT * FROM exp_csv WHERE fsgml = $1 ";
		break;

	case 'grundbuch': // ax_buchungsblatt.gml_id
		$sql="SELECT * FROM exp_csv WHERE gbgml = $1 ";
		break;

	case 'buchung': // ax_buchungsstelle.gml_id (bei "Recht an" die herrschende Buchung)
		$sql="SELECT * FROM exp_csv WHERE gsgml = $1 "; // 2022-11-02: gsgml im View eingefügt
		break;

	case 'person': // ax_person.gml_id
		$sql="SELECT * FROM exp_csv WHERE psgml = $1 ";
		break;

	case 'strasse': // ax_lagebezeichnungkatalogeintrag.gml_id = Straße-GML-ID
		// alternativer View mit "_str", ist in der Datenbank gespeichert
		$sql="SELECT * FROM exp_csv_str WHERE stgml = $1 ";
		break;

	case 'gemarkung': // SONDERfall als Parameter wird "Gemarkungsnummer" und nicht "gml_id" geliefert
		$sql="SELECT * FROM exp_csv WHERE gemarkungsnummer = $1 ";
		$v=array($gemarkung);
		break;

	case 'flstliste':
	// GML-ID aufgeteilt: 6 Byte konstant, 10 Byte variabel in Liste
		$sql="SELECT * FROM exp_csv WHERE substring(fsgml from 1 for 6) = $1 "
			." AND substring(fsgml from 7 for 10) IN ('".str_replace(",", "','", $gmlliste)."')";
		$v=array($prefix);
		break;

	default:
		$err="\nFalscher Parameter '".$tabtyp."'";
		exit($err);
		break;
}

if ($haus === 'm' or $haus === 'o') { // nur FS mit/ohne verschl. Lagebez.
//	if ($gmlid === '') { // m/o-Filter als einziger Filter
//		$sql="SELECT * FROM ".$viewname." WHERE fall='".$haus."' "; // Ersetzen
//		$v=array(); // kein Filter-Feld
//		// ToDo: Filter auf Gemeinde notwendig, wenn nicht auf strasse gefiltert wird.
//		// - Sonst Ausgabe von Rand-Flurstücken (bei geometrischer Filterung des NBA-Verfahrens)
//		// - Sonst ggf. Ausgabe Kreisgebiet
//	} else { // als zusätzlicher Filter AND

		$sql.=" AND fall='".$haus."' "; // m/o-Filter Anhängen

//	}
}

$res=pg_prepare($con, "", $sql);
$res=pg_execute($con, "", $v);
if (!$res) {
	$err="\nFehler bei Datenbankabfrage";
	exit($err);
}
$i=1; // Kopfzeile zählt mit
$fsalt='';

// Datenfelder auslesen
while($row = pg_fetch_assoc($res)) {
	$i++; // Zeile der Tabelle
	$rechnen=true; // Formel in letzte Spalte?

	// Flurstueck
	$fsgml=$row["fsgml"];
	$fs_kennz=$row["fs_kennz"]; // Rechts Trim "_" ?
	$gmkgnr=$row["gemarkungsnummer"];
	$gemkname=$row["gemarkung"]; 
	$flurnummer=$row["flurnummer"];
	$flstnummer=$row["zaehler"];
	$nenner=$row["nenner"];
	// Bruchnummer kann in Excel als Datum interpretiert werden. In '' setzen.
	if ($nenner > 0) {$flstnummer="'".$flstnummer."/".$nenner."'";} // BruchNr
	$fs_flae=$row["fs_flae"]; // amtliche Fl. aus DB-Feld

	// Grundbuch (Blatt)
	$gb_bezirk=$row["gb_bezirk"]; // Nummer des Bezirks
    $gb_beznam=$row["beznam"];    // Name des Bezirks
	$gb_blatt=$row["gb_blatt"];

	// Buchungsstelle (Grundstueck)
	$bu_lfd=$row["bu_lfd"]; // BVNR
	$bu_ant=$row["bu_ant"]; // '=zaehler/nenner' oder NULL
	$bu_key=$row["buchungsart"]; // Schlüssel
	$bu_art=$row["bu_art"]; // entschlüsselt (Umlaute in ANSI!)
	if($bu_ant == '') { // Keine Bruch-Zahl
		$bu_ant = '1'; // "voller Anteil" (Faktor 1)
	} else {
		$bu_ant=str_replace(".", ",", $bu_ant); // Dezimalkomma statt -punkt.		
	}

	// Namensnummer
	$nam_lfd="'".kurz_namnr($row["nam_lfd"])."'"; // In Hochkomma, wird sonst wie Datum dargestellt.
	$nam_ant=$row["nam_ant"];
	$nam_adr=$row["nam_adr"]; // Art der Rechtsgemeischaft (Schlüssel)

	if ($nam_adr == '') {     // keine Rechtsgemeinschaft
		$rechtsg='';
		if ($nam_ant == '') { // und kein Bruch-Anteil
			$nam_ant=1; // dann ganzer Anteil
		}
	} else {
		$rechnen=false; // bei Rechtsgemeinschaft die Anteile manuell interpretieren
		if ($nam_adr == 9999) { // sonstiges
			$rechtsg=$row["nam_bes"]; // Beschrieb der Rechtsgemeinschaft
		} else {
		//	$rechtsg=rechtsgemeinschaft($nam_adr); // Entschlüsseln
			$rechtsg=$row["nam_adrv"]; // Art der Rechtsgemeischaft (Value zum Key)
		}
	}

	// Person
	$vnam=$row["vorname"];
	$nana=$row["nachnameoderfirma"];
	$namteil=$row["namensbestandteil"];
	//$name=anrede($row["anrede"]);  
	$name=$row["anrv"]; // Anrede (Value zum Key)
	if ($name != "") {$name.=" ";} // Trenner
	if ($namteil != "") {$name.=$namteil." ";} // von und zu
	$name.=$nana;
	if ($vnam != "") {$name.=", ".$vnam;} // Vorname nach hinten
	$gebdat=$row["geburtsdatum"];
	// Koennte man im View in deutsches Format umwandeln: "to_char(cast(geburtsdatum AS date),'DD.MM.YYYY') AS geburtsdatum"

	// Adresse der Person (Eigentuemer)
	// Im View ist per subquery geregelt, dass nur die "juengste" Adresse verwendet wird.
	$ort=$row["ort"];
	if ($ort == "") {
		$adresse="";
	} else { 
		$adresse=$row["strasse"]." ".$row["hausnummer"].", ".$row["plz"]." ".$ort;
		$land=$row["land"]; // nur andere Laender anzeigen
		if (($land != "DEUTSCHLAND") and ($land != "")) {
			$adresse.=" (".$land.")";
		}
	}

	// Adressen (Lage) zum FS
	if($fsgml != $fsalt) { // nur bei geändertem Kennz.
		$lage=lage_zum_fs($fsgml); // die Lage neu ermitteln
		$fsalt=$fsgml;
	}

	// Den Ausgabe-Satz montieren aus Flurstücks-, Grundbuch- und Namens-Teil
	//      A             B           C             D               E               F            G
	$fsteil=$fs_kennz.";".$gmkgnr.";".$gemkname.";".$flurnummer.";".$flstnummer.";".$fs_flae.";".$lage.";";
	//      H              I              J             K           L           M
	$gbteil=$gb_bezirk.";".$gb_beznam.";".$gb_blatt.";".$bu_lfd.";".$bu_ant.";".$bu_art.";";
	//       N            O            P            Q         R           S
	$namteil=$nam_lfd.";".$nam_ant.";".$rechtsg.";".$name.";".$gebdat.";".$adresse;

	// Anteile "GB am FS" und "Pers am GB" verrechnen
	if ($rechnen) { // beide Anteile verwertbar
		$formelteil=";=L".$i."*O".$i; // Spalte T
	} else {
		$formelteil=';';
	}

	// Ausgabe in die CSV-Datei -> Download -> Tabellenkalkulation
	echo "\n".$fsteil.$gbteil.$namteil.$formelteil;
}
pg_free_result($res);
if ($i === 1) { // nur Kopf
	if ($gmlid == '') {
		$err="\nKein Treffer";
	} else {
		$err="\nKein Treffer fuer gml_id='".$gmlid."'";
	}
	exit ($err);
}
pg_close($con);
exit(0);
?>
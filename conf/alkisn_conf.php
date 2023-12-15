<?php
/*	alkis_conf.php
	ALKIS-Buchauskunft, Kommunales Rechenzentrum Minden-Ravensberg/Lippe (Lemgo).
	Zentrale Einstellungen
....
	2020-02-20 
	2020-12-16 Sonderfall QWC2 API-Gateway-Umleitung bei Selbstverlinkung
	2021-12-07 Neue Parameter $katAmtMix (Kataster-Amt-Mix) und $fsHistorie
	2021-12-09 Neuer Parameter $PrntBtn (Drucken-Schaltfl�che)
*/

//	Default Bundesland-Schl�ssel, falls dieser bei Anfragen ausgelassen wird
	$defland='05'; // NRW

//	Datenbank-Zugangsdaten
	$dbport = '5432';
	$dbuser = '****';
	$dbpass = '****';
	$dbpre  = 'alkis0'; // Prefix des DB-Namens, dahinter das GKZ anh�ngen
	$dbsuf  = '';       // Suffix des DB-Namens, z.B. Anhang "_neu"

//	im Normalfall  ...
	$dbgkz = $gkz;				// normalerweise eine Datenbank je Mandant
	$dbhost = '10.0.**.**';		// Datenbank-Server aus Produktion

//	Gebiets-Filter
	$filtland=$defland;			// ax_gemeinde.land
	$filtrgb='7';				// ax_gemeinde.regierungsbezirk
	$filtkreis='';				// ax_gemeinde.kreis
	$filtgem = '';				// ax_gemeinde.gemeinde

	$katAmtMix = false;			// Sind Daten aus verschiedenen Katasteramts-Bezirken in der DB? (Default)
								// Erzeugt kompliziertere Joins auf Schl�sseltabellen zur Unterdr�ckung doppelter Treffer.

	$fsHistorie = true;			// Ist eine Flurst�cks-Historie gef�llt? (Default)
								// Eintr�ge in den Tabellen: ax_historischesflurstueck, ax_historischesflurstueckohneraumbezug, ax_historischesflurstueckalb.
								// Steuert den Link auf das Historie-Modul.

	$PrntBtn = true;			// Drucken-Schaltfl�che im Seitenfu� anzeigen? 
								// Default = true. false wenn die Umgebung bereits eine Schaltfl�che bietet.

	switch ($gkz) {	// lokale und tempor�re Besonderheiten je Mandant

		// Kreisgebiet Lippe
		case "150": // Lage
			$filtkreis='66';
			$filtgem = '040';
			break;

		// Kreisgebiet Herford
		case "210": // B�nde
			$filtkreis='58';
			$filtgem = '004';
			break;

		// Kreisgebiet Minden-L�bbecke.
		case "320": // H�llhorst
		//	$dbgkz = '300';  // Gemeinsame Kreis-Datenbank f�r mehrere St�dte
			$filtkreis='70';
			$filtgem = '016';
			break;

		case "418":		// Stadtwerke
			$katAmtMix = true;		// Katasteramt 18 und 15
			$fsHistorie = false;	// keine Historie
			break;
	}

//	Authentifizierung
//	$auth="mapbender";
	$auth=""; 				// deaktiviert
	$mb_guest_user='gast';	// ausschlie�en

//	Bei Verlinkung auf das gleiche Modul (mit anderen Parametern) den Pfad entfernen?
//	0 = Normaler Webserver. Die Systemvariable zeigen den korrekten Pfad.
//	1 = Umleitung �ber API-Gateway QWC2. Den Pfad entfernen bei Verlinkung weil Systemvariable $_SERVER['PHP_SELF'] nicht korrekt ist.
	$pfadlos_selbstlink = 0;

//	Entwicklungsumgebung
	$debug = 0; // 0=Produktion 1=mit Fehlermeldungen, 2=mit Informationen, 3=mit SQL

//	Link f�r Hilfe (cmsimpe_xh)
	$hilfeurl = 'http://mapserver.krz.de/?Karten/ALKIS/ALKIS-Auskunft';

//	Den Datenbank-Connection-String aus den oben konfigurierten Parametern bilden
	$dbconn = "host=".$dbhost." port=" .$dbport." dbname=".$dbpre.$dbgkz.$dbsuf." user=".$dbuser." password=".$dbpass;

//	Je Modul noch individuell anh�ngen: " options='--application_name=ALKIS-Auskunft_programmname.php'"
//	In postgresql.conf:
//		log_line_prefix = '%t [%a-%h] %q%u@%d '
//	wobei %a = Application

?>
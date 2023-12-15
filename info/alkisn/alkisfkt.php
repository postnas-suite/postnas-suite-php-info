<?php
/*	alkisfkt.php

	ALKIS-Auskunft
	Author: Frank Jäger, Kommunales Rechenzentrum Minden-Ravensberg/Lippe (Lemgo)

	F u n c t i o n s , die von mehreren Modulen verwendet werden.

	Version:
	2016-02-24 Version für norGIS-ALKIS-Import, "function linkgml" raus. Case-Entschlüsselung raus.
	....
	2020-12-03 function selbstverlinkung() statt $_SERVER['PHP_SELF'] für Einsatz hinter Gateway mit Änderung des Pfades (Docker/QWC2)
	2020-12-09 Entschl. Bodenschätzung korrigiert in function werteliste()
	2020-12-15 Input-Validation und Strict Comparisation (===)
	2020-12-16 Sonderfall QWC2 API-Gateway-Umleitung bei Selbstverlinkung
	2021-12-09 Neue Parameter: $katAmtMix (Kataster-Amt-Mix), $PrntBtn (Drucken-Schaltfläche)
			   Footer: Umschalter für Schlüssel und Debug unabhängig benutzbar. Authentifizierung aus QWC2 nicht hier behandeln.
	2021-12-30 Bestandsnachweis recursiv über alle Buchungs-Ebenen
	2022-01-13 Functions in Fach-Modul verschoben, wenn nur von einem verwendet. Neue Functions LnkStf(), DsKy()
	2022-07-05 PHP 8.1: Connection verwenden bei "pg_prepare" und "pg_execute", keine NULL-Werte in String-Functions verwenden
*/

function selbstverlinkung() {
//	Aus der Server-Variable den Pfad entfernen.
	global $pfadlos_selbstlink;
	If ($pfadlos_selbstlink === 1) { // Selbstverlinkung ohne Pfad z.B. hinter QWC2 API-Gateway (Umleitung)
		$mod=strrchr($_SERVER['PHP_SELF'], '/');
		$mod=substr($mod, 1);
	} else { // normaler Webserver, die Systemvariable ist korrekt
		$mod=$_SERVER['PHP_SELF'];
	}
	return $mod;
}

function darf_ich() {
//	Am Anfang jedes Moduls aufrufen um $customer zu füllen.	
//	Der automatisch einloggende anonyme Gast-Benutzers muss bei der Authentifizierung ausgeschlossen werden.
	global $auth, $customer, $mb_guest_user, $dbg;

	if ($auth === "") {		// nicht prüfen
		$customer = "";		// dann anonym
		return 1;			// alles erlaubt
	} elseif ($auth === "mapbender") {
		$customer = "";
		include '/opt/gdi/mapbender/http/php/mb_validateSession.php';

		if (!isset($_SESSION)) { // keine (passende) Session
			if ($dbg > 1) {echo "\n<p class='dbg'>Session nicht gesetzt</p>";}
		} elseif ( !isset($_SESSION["mb_user_name"]) )  {
			if ($dbg > 1) {echo "\n<p class='dbg'>username nicht gesetzt</p>";}
		} else {
			$customer = $_SESSION["mb_user_name"]; // angemeldeter Benutzer	
		}
		if ($customer == "") {	// Wer bin ich?
			echo "<p class='stop2'>Aufruf nur aus Mapbender erlaubt.</p>";
			return 0;
		} elseif ($customer == $mb_guest_user) { // in conf festgelegt
			echo "<p class='stop2'>Eine Anmeldung im Mapbender ist notwendig.</p>";
			return 0;	// gast-User darf nix
		} else {
			return 1;	// echter User, ist erlaubt
		}
	} else {
		echo "\n<p class='stop2'>Die Berechtigungs-Pr&uuml;fung ist falsch konfiguriert</p>";
		return 0;	// verboten
	}
}

function footer($gmlid, $link, $append) {
// Einen Seitenfuß ausgeben.
// Die Parameter &gkz= und &gmlid= kommen in allen Modulen einheitlich vor
// Den URL-Parameter "&showkey=j/n" umschalten lassen.
// $append wird angehängt wenn gefüllt. Anwendung: &eig=j bei FSNW, &ltyp=m/p/o bei Lage
	global $gkz, $showkey, $hilfeurl, $debug, $dbg, $customer;

	echo "\n<footer>";
	// S c r e e n - F o o t
	echo "\n\t<div class='confbereich noprint'>"
	."\n\t\t<table class='outer'>\n\t\t<tr>";

	// Sp.1: Info Benutzerkennung
	if (isset($customer) and $customer != '') { // über global von fkt. darf_ich()
		echo "\n\t\t\t<td title='Info'><i>Benutzer:&nbsp;".$customer."</i></td>";
	} else {
		echo "\n\t\t\t<td>&nbsp;</td>";
	}

	// Sp.2: Umschalter
// +++ ToDo: Texte eindeutiger machen, z.B. Anzeige der Schlüssel ist an >> aus // Anzeige der Schlüssel ist aus >> an
// oder als Formular / Option-Element
	echo "\n\t\t\t<td title='Konfiguration'>";
		$mylink ="\n\t\t\t\t<a class='keyswitch' href='".$link."gkz=".$gkz."&amp;gmlid=".$gmlid.$append;

		if ($showkey) { // Umschalten Schlüssel ein/aus
			echo $mylink."&amp;showkey=n";
			if ($debug > 0 and $dbg == 0) {echo "&amp;nodebug=j";}
			echo "' title='Verschl&uuml;sselungen ausblenden'>Schl&uuml;ssel aus</a>";
			$mylink.="&amp;showkey=j";
		} else {
			echo $mylink."&amp;showkey=j";
			if ($debug > 0 and $dbg == 0) {echo "&amp;nodebug=j";}
			echo "' title='Verschl&uuml;sselungen anzeigen'>Schl&uuml;ssel ein</a>";
			$mylink.="&amp;showkey=n";
		}

		if ($debug > 0) {	// nur für Entwicklung
			if ($dbg > 0) {	// temporär eine Ansicht OHNE debug
				echo "<br>".$mylink."&amp;nodebug=j' title='Debug-Ausgaben tempor&auml;r abschalten'>Testausgaben aus</a>";
			} else {		// Abschaltung beenden
				echo "<br>".$mylink."' title='Debug-Ausgaben wie konfiguriert'>Testausgaben ein</a>";
			}
		}

	echo "\n\t\t\t</td>";

	// Sp.3: Dokumentation
	echo "\n\t\t\t<td title='Hilfe'>"
		."\n\t\t\t\t<p class='nwlink'>\n\t\t\t\t\t<a target='_blank' href='".$hilfeurl."' title='Dokumentation'>Hilfe zur ALKIS-Auskunft</a>\n\t\t\t\t</p>\n\t\t\t</td>"
		."\n\t\t</tr>\n\t\t</table>"
	."\n\t</div>";

	// P r i n t - F o o t
	if (isset($customer) and $customer != '') {
		echo "\n\t<p class='onlyprint'><i>Benutzer:&nbsp;".$customer."</i></p>";
	}

	echo "\n</footer>\n";
	return 0;
}

function UnqKatAmt($t1, $t2){
// Wenn der Datenbestand aus NBA-Verfahren mehrerer Katasterämter gemixt wurde, dann muss beim SQL-JOIN auf einige Schlüsseltabellen
// zusätzlich dafür gesort werden, dass nur die Schlüssel des gleichen Katasteramtes verwendet werden. Sonst bekommt man redundante Treffer.
// Benötigt den Alias der zu verbindenden Tabellen.
// Liefert einen String zum Einfügen hinter "JOIN .. ON".
	global $katAmtMix; // aus Conf
	if ($katAmtMix){
		return "AND substr(".$t1.".gml_id,1,6) = substr(".$t2.".gml_id,1,6) ";
	} else {
		return "";
	}
}

function LnkStf(){
// Link-Staffeltab - Die Parameter showkey und nodebug im href eines <a>-Tag an ein anderes Modul weiter geben.
// Gibt einen String zurück, der im href eingefügt wird.
	global $debug, $dbg, $showkey;

	if ($showkey) { // Schlüssel anzeigen
		$ret="&amp;showkey=j";
	} else {
		$ret="";
	}

	// Nur relevant in einer Entwicklungsumgebung:
	// Falls debug-Ausgaben erlaubt sind (conf) kann man das temporär einschränken, umgekehrt nicht.
	// $dbg = aktueller Arbeitswert, $debug = aus Conf.
	if ($dbg === 0 AND $debug > 0){
		$ret.="&amp;nodebug=j";
	}
	return $ret;
}

function DsKy($derKey, $Tipp){
// Display Key - Optional einen ALKIS-internen Schlüsselwert vor dem entschlüsselten Wert ausgeben.
// Die Option wird gesteuert durch einen Schalter im Seitenfuß.
// Liefert einen HTML-Text zur Verwendung in einem Echo-Befehl. Zur Verkettung mit Literalen.
	global $showkey;
	$Tipp = str_replace("*", "Schl&uuml;ssel", $Tipp); // häufig verwendet
	if ($showkey and $derKey != "") {
		$html="<span class='key' title='".$Tipp."'>(".$derKey.")&nbsp;</span>";
	} else {
		$html="";
	}
	return $html;
}

function ber_bs_zaehl($gmls) {
// Berechtigte Buchungs-Stellen zählen.
	global $con;

	// Buchungstelle dienend <(Recht)an< Buchungstelle herrschend
	$sql ="SELECT count(sh.gml_id) AS anz FROM ax_buchungsstelle sd JOIN ax_buchungsstelle sh ON sd.gml_id=ANY(sh.an) " 
	."WHERE sd.gml_id= $1 AND sh.endet IS NULL AND sd.endet IS NULL;";
	$v = array($gmls); // GML dienende Buchungs-Stelle
	$resan = pg_prepare($con, "", $sql);
	$resan = pg_execute($con, "", $v);
	if (!$resan) {echo "\n<p class='err'>Fehler bei 'berechtigte Buchungsstellen z&auml;hlen'.</p>";}
	$rowan = pg_fetch_assoc($resan);
	$anz=$rowan["anz"];
	pg_free_result($resan);
	return $anz; // Funktionswert = Anzahl der berechtigten Buchungen
}

function buchung_anzg($gmlbs, $eig, $jsfenster, $gml_fs, $trtyp) {
// In einem FS-Nachw. EINE Buchungsstelle anzeigen.
// Parameter:
//  $gmlbs: GML-ID der anzuzeigenden Buchungs-Stelle
//  $eig: Eigentümer ausgeben j/n
//  $jsfenster: Javascript-Funktion zum Verlassen des Feature-Info-Fensters verwenden (bool)
//  $gml_fs: GML-ID des Flurstücke (nur bei erstem Aufruf in einem FS-Nachweis notwendig)
//	$trtyp: Tabellen-Zeilen-Typ. Werte: 1="mit GS-Link", 2="ohne GS-Link", 3="ohne GS-Link +Zeile einfärben"
	global $gkz, $dbg, $showkey, $bartgrp, $barttypgrp, $stufe, $katAmtMix, $con;

	$sqlbs="SELECT sh.gml_id AS hgml, sh.buchungsart, sh.laufendenummer as lfd, sh.zaehler, sh.nenner, sh.nummerimaufteilungsplan as nrpl, sh.beschreibungdessondereigentums as sond, " // Buchungs-Stelle herrschend
	."b.gml_id AS g_gml, b.bezirk, b.buchungsblattnummermitbuchstabenerweiterung as blatt, b.blattart, z.bezeichnung, a.beschreibung AS bart, a.dokumentation AS barttitle, w.beschreibung AS blattartv "
	."FROM ax_buchungsstelle sh "
	."JOIN ax_buchungsblatt b ON b.gml_id=sh.istbestandteilvon "
	."LEFT JOIN ax_buchungsblattbezirk z ON z.land=b.land AND z.bezirk=b.bezirk ".UnqKatAmt("z","b")
	."LEFT JOIN ax_buchungsart_buchungsstelle a ON sh.buchungsart = a.wert " // entschl. Buchungsart
	."LEFT JOIN ax_blattart_buchungsblatt w ON b.blattart = w.wert " // entschl. Blatt-Art
	."WHERE sh.gml_id= $1 AND sh.endet IS NULL AND b.endet IS NULL AND z.endet IS NULL;";

	$v = array($gmlbs); // ID dienende Buchungs-Stelle
	$resbs = pg_prepare($con, "", $sqlbs);
	$resbs = pg_execute($con, "", $v);
	if (!$resbs) {
		echo "\n<p class='err'>Fehler bei 'Buchungsstelle ausgeben'.</p>";
		if ($dbg > 2) {echo "\n<p class='dbg'>SQL=<br>".htmlentities(str_replace("$1", "'".$gmlbs."'", $sqlbs), ENT_QUOTES, "UTF-8")."</p>";}
	}
	$gezeigt = 0; // Funktionswert default
	if ($dbg > 0) {
		$zeianz=pg_num_rows($resbs);
		if ($zeianz > 1){
			echo "\n<p class='err'>Die Abfrage liefert mehr als eine (".$zeianz.") Buchung!</p>";
			if ($dbg > 2) {echo "\n<p class='dbg'>SQL=<br>".htmlentities(str_replace("$1","'".$gmlid."'",$sqlbs), ENT_QUOTES, "UTF-8")."</p>";}
		}
	}
	if ($rowbs = pg_fetch_assoc($resbs)) { // EIN Treffer
		$blattkeyber=$rowbs["blattart"]; // Schlüssel von Blatt-Art des GB
		if ($blattkeyber != '5000' or $dbg > 1) { // "Fiktives Blatt" nur bei Entwicklung anzeigen
			$gezeigt = 1; // Funktionswert nach Treffer
			$hgml=$rowbs["hgml"]; // GML-ID der (herrschenden) BuchungsStelle
			$bartkey=$rowbs["buchungsart"]; // Buchungsart Schlüssel
			$bart=$rowbs["bart"]; // Buchungsart Text
			$beznam=$rowbs["bezeichnung"]; // Bezirk
			$blattartber=$rowbs["blattartv"]; // Wert von Blatt-Art des GB
			$nrpl=$rowbs["nrpl"]; // Nr im Auft.plan
			$sond=$rowbs["sond"]; // Beschr. d.Sondereigentums
			$gbgml=$rowbs["g_gml"]; // GML-ID des Grundbuch-Blattes
			$blatt=ltrim($rowbs["blatt"], "0");
			$lfd=ltrim($rowbs["lfd"], "0");
			if ($bartkey != $bartgrp) { // Wechsel Buchungsart
				$barttitle=$rowbs["barttitle"]; // Buchungsart Erklärung
				switch (true) { // Die Buchungsart einem Typ (Gruppierung) zuweisen
					case ($bartkey <= 1999): $barttyp = "E"; break; // Eigentum/Grundstück
					case ($bartkey >= 2000): $barttyp = "R"; break; // Grundstücksgleiches Recht, z.B. 2101 "Erbbaurecht"
					default: $barttyp = "E"; break;
				}
				if ($barttypgrp != $barttyp) { // Wenn der Typ wechselt, neue Überschrift in Tabelle
					switch ($barttyp) { // Text der Überschrift
						case "E":
							if ($eig === 'j') {$h3txt = "Buchung und Eigentum";} 
							else {$h3txt = "Buchung";}
						break;
						case "R": $h3txt = "Grundst&uuml;cksgleiche Rechte"; break;
					}
					if ($barttypgrp === "" and $gml_fs != "") { // die erste Überschrift mit ID und Umschalter
						echo "\n\t<tr>\n\t\t<td colspan='3'>\n\t\t\t<h3 id='gb'>".$h3txt."</h3>\n\t\t</td>"; // 1-3
						echo "\n\t\t<td>\n\t\t\t<p class='nwlink noprint'>" // 4
							."\n\t\t\t\t<a href='".selbstverlinkung()."?gkz=".$gkz."&amp;gmlid=".$gml_fs.LnkStf();
							if ($eig=="j") { // Umschalter: FS-Nachweis ruft sich selbst mit geändertem Param. auf. Pos. auf Marke "#gb"
								echo "&amp;eig=n#gb' title='Umschalter: Flurst&uuml;cksnachweis'>ohne Eigent&uuml;mer</a>";
							} else {
								echo "&amp;eig=j#gb' title='Umschalter: Flurst&uuml;cks- und Eigent&uuml;mernachweis'>mit Eigent&uuml;mer "
								."<img src='ico/EigentuemerGBzeile.png' width='16' height='16' alt=''></a>";
							}
						echo "\n\t\t\t</p>\n\t\t</td>";
					} else {
						echo "\n\t<tr>\n\t\t<td colspan='3'>\n\t\t\t<h3>".$h3txt."</h3>\n\t\t</td>\n\t\t<td>&nbsp;</td>"; // 1-4
					}
					echo "\n\t</tr>";
					$barttypgrp = $barttyp;
				}
				echo "\n\t<tr>" // Buchungsart als Zwischenzeile
					."\n\t\t<td class='ll'><img src='ico/Grundbuch.png' width='16' height='16' alt=''> Buchungsart:</td>"
					."\n\t\t<td colspan='2' title='".$barttitle."'>".DsKy($bartkey, 'Buchungsart')."<span class='wichtig'>".$bart."</span>"
					."</td>\n\t\t<td></td>" // 4
				."\n\t</tr>";
				$bartgrp=$bartkey; // Gruppe merken
			} // Ende Wechsel der Buchungsart

			echo "\n\t<tr>" // Zeile mit 4 Spalten für Buchung und Eigentümer
				."\n\t\t<td class='ll'><img src='ico/Grundbuch_zu.png' width='16' height='16' alt=''> Buchung:"; // 1
			if ($showkey and $dbg > 2) {echo "<br><span class='key'>Stufe ".$stufe."<br>".$hgml."</span> ";}
			echo "</td>\n\t\t<td colspan='2'>"; // 2-3

					// innere Tabelle: Rahmen mit GB-Kennz.
					if ($blattkeyber == 1000) {
						echo "\n\t\t\t<table class='kennzgb' title='Bestandskennzeichen'>";
					} else {
						echo "\n\t\t\t<table class='kennzgbf' title='Bestandskennzeichen'>"; // dotted
					}
					echo "\n\t\t\t<tr>"
					."\n\t\t\t\t<td class='head'>Bezirk</td>"
					."\n\t\t\t\t<td class='head'>".DsKy($blattkeyber, 'Blattart-*').$blattartber."</td>"
					."\n\t\t\t\t<td class='head'>Lfd-Nr</td>"
					."\n\t\t\t</tr>";

					if ($trtyp === 3) { // Treffer-Grundst. einfärben
						echo "\n\t\t\t<tr class='paa'>";
					} else {
						echo "\n\t\t\t<tr>";
					}
					echo "\n\t\t\t\t<td title='Grundbuchbezirk'>".DsKy($rowbs["bezirk"], 'GB-Bezirk-*').$beznam."</td>"
					."\n\t\t\t\t<td title='Grundbuch-Blatt'><span class='wichtig'>".$blatt."</span></td>"
					."\n\t\t\t\t<td title='Bestandsverzeichnis-Nummer (BVNR, Grundst&uuml;ck)'>".$lfd."</td>"
					."\n\t\t\t</tr>"
					."\n\t\t\t</table>";

					if ($rowbs["zaehler"] != "") {
						echo "\n\t\t\t<p class='ant'>".$rowbs["zaehler"]."/".$rowbs["nenner"]."&nbsp;Anteil am Flurst&uuml;ck</p>";
					}
					if ($nrpl != "") {
						echo "\n\t\t\t<p class='nrap' title='Nummer im Aufteilungsplan'>Nummer <span class='wichtig'>".$nrpl."</span> im Aufteilungsplan.</p>";
					}
					if ($sond != "") {
						echo "\n\t\t\t<p class='sond' title='Sondereigentum'>Verbunden mit dem Sondereigentum: ".$sond."</p>";
					}
				echo "\n\t\t</td>"; // 2-3

				echo "\n\t\t<td>"; // 4
					echo "\n\t\t\t<p class='nwlink noprint'>".DsKy($blattkeyber, 'Blattart-*');
					//	Bestand
						$url="alkisbestnw.php?gkz=".$gkz."&amp;gmlid=".$gbgml.LnkStf();
						if ($jsfenster) {$url="javascript:imFenster(\"".$url."\")";} // Sonderfall "Inlay" aus Feature-Info
						echo "\n\t\t\t\t<a href='".$url."' title='Grundbuchnachweis'>".$blattartber
						." <img src='ico/GBBlatt_link.png' width='16' height='16' alt=''></a>";
					//	Buchung
						if ($trtyp === 1) {
							echo "<br>".DsKy($bartkey, 'Buchungsart');
							$url="alkisgsnw.php?gkz=".$gkz."&amp;gmlid=".$hgml.LnkStf();
							if ($jsfenster) {$url="javascript:imFenster(\"".$url."\")";}
							echo "\n\t\t\t\t<a href='".$url."' title='Grundstücksnachweis: ".$bart."'>Buchung"
							." <img src='ico/Grundstueck_Link.png' width='16' height='16' alt=''></a>";
						}
					echo "\n\t\t\t</p>"
				."\n\t\t</td>"
			."\n\t</tr>";
			if ($eig === "j") {
				$n = eigentuemer($gbgml, true, $jsfenster); // mit Adresse
			}
		}
	}
	pg_free_result($resbs);
	return $gezeigt; // 1 wenn eine Buchung ausgegeben wurde
}

function ber_bs_anzg($gmls, $eig, $jsfenster, $gml_fs, $gsanfrd) {
// In einem FS-Nachw. die berechtigten (herrschenden) Buchungsstellen anzeigen z.B. "Wohnungs-/Teileigentum".
// Parameter: 
//  $gmls: GML-ID der dienenden Buchungs-Stelle. Im ersten Durchlauf also, die BS auf der das FS gebucht ist.
//  $eig: Eigentümer ausgeben j/n
//  $jsfenster: Javascript-Funktion zum Verlassen des Feature-Info-Fensters verwenden (bool)
//  $gml_fs: GML-ID des Flurstücke (nur bei erstem Aufruf in einem FS-Nachweis notwendig)
//	$gsanfrd: In einem GS-Nachw. die GML-ID der in de URL angeforderten Buchungsstelle (-> Hervorhebung)
	global $dbg, $gezeigt, $con;

	// sh=Buchungstelle herrschend >(Recht)an> sd=Buchungstelle dienend >istBestandteilVon> BLATT -> Bezirk
	$sql="SELECT sh.gml_id AS hgml, sh.buchungsart, sh.laufendenummer as lfd, sh.zaehler, sh.nenner, sh.nummerimaufteilungsplan as nrpl, sh.beschreibungdessondereigentums as sond, "
	."b.gml_id AS g_gml, b.bezirk, b.buchungsblattnummermitbuchstabenerweiterung as blatt, b.blattart "
	."FROM ax_buchungsstelle sh JOIN ax_buchungsblatt b ON b.gml_id=sh.istbestandteilvon "
	."WHERE $1 = ANY(sh.an) AND sh.endet IS NULL AND b.endet IS NULL "
	."ORDER BY b.bezirk, b.buchungsblattnummermitbuchstabenerweiterung, sh.laufendenummer;";

	$v = array($gmls); // ID dienende BuchungsStelle
	$resber = pg_prepare($con, "", $sql);
	$resber = pg_execute($con, "", $v);
	if (!$resber) {
		echo "\n<p class='err'>Fehler bei 'berechtigte Buchungsstellen'.</p>";
		if ($dbg > 2) {echo "\n<p class='dbg'>SQL=<br>".htmlentities(str_replace("$1", "'".$gmls."'", $sql), ENT_QUOTES, "UTF-8")."</p>";}
	}
	$an=0;
	$verfolg=array(); // Ein (zunächst leeres) Array für weitere GML-IDs anlegen
	while($rowan = pg_fetch_assoc($resber)) { // Loop durch Result von berechtigte BS
		$hgml=$rowan["hgml"]; // GML-ID der berechtigten Buchungs-Stelle

		if ($hgml === $gsanfrd) {
			$trtyp=3; // einfärben, o. Lnk.
		} else {
			if ($gezeigt) {
				$trtyp=2; // ohne Link auf GS
			} else { // Wenn Stufe 1 "Fiktives Blatt" war, welches ausgeblendet wurde, dann auf Stufe 2 einen GS-Link ausgeben
				$trtyp=1; // mit Link auf GS
			}
		}
		$gezeigt2=buchung_anzg($hgml, $eig, $jsfenster, $gml_fs, $trtyp); // Die herrschende Buchung anzeigen wenn nicht fiktiv
		$an++;
		$weitere=ber_bs_zaehl($hgml); 
		if ($weitere > 0) { // gibt es WEITERE berechtigte Buchungen dazu?
			$verfolg[] = $hgml; // neuen Wert für weitere Verfolgung in Array anfügen
		}
	}
	pg_free_result($resber);
	return $verfolg; // weitere zu verfolgende GML-ID zurück geben
}

function kurz_namnr($lang) {
// Namensnummer kürzen. Nicht benötigte Stufen der Dezimalklassifikation abschneiden
	$kurz=str_replace(".00","",$lang); // leere Stufen (nur am Ende)
	$kurz=str_replace("0000","",$kurz); // ganz leer (am Anfang)
	$kurz=ltrim($kurz, "0"); // führd. Nullen
	$kurz=str_replace(".0",".",$kurz); // führd. Null jeder Stufe
	$kurz=rtrim($kurz); // Leerzeichen hinten
	return $kurz;
}

function eigentuemer($gmlid, $mitadresse, $jsfenster) {
/*	Tabelle mit Eigentümerdaten zu einem Grundbuchblatt ausgeben
	Sp.1="Eigentümer" Sp.2=NamNr, Sp.3=Name/Adresse, Sp.4=Link
  Parameter:
	$gmlid: ID GB-Blatt
	$mitadresse: Option (t/f) ob die Adresszeile ausgegeben werden soll
	$jsfenster: Beim Link mit Javascript ein neues Fenster öffnen
  Return = Anzahl Namensnummern */
	global $dbg, $gkz, $showkey, $con;
	if ($jsfenster) { // beim Link aus iFrame ausbrechen
		$lnkvor  = "javascript:imFenster(\"";
		$lnknach = "\")";		
	} else { 	
		$lnkvor = "";
		$lnknach = "";
	}

	// N a m e n s n u m m e r
	// ax_namensnummer >istBestandteilVon> ax_buchungsblatt
	$sqln="SELECT n.gml_id, n.laufendenummernachdin1421 AS lfd, n.zaehler, n.nenner, n.artderrechtsgemeinschaft AS adr, coalesce(n.beschriebderrechtsgemeinschaft, '') as beschr, n.eigentuemerart, n.anlass, n.benennt, "
	."coalesce(wn.beschreibung, '') AS adrv, we.beschreibung AS eiartv, "
	."p.gml_id AS gmlpers, p.nachnameoderfirma, p.vorname, p.geburtsname, to_char(cast(p.geburtsdatum AS date),'DD.MM.YYYY') AS geburtsdatum, p.namensbestandteil, p.akademischergrad "
	."FROM ax_namensnummer n "
	."LEFT JOIN ax_artderrechtsgemeinschaft_namensnummer wn ON n.artderrechtsgemeinschaft = wn.wert "
	."LEFT JOIN ax_eigentuemerart_namensnummer we ON n.eigentuemerart = we.wert "	
	."LEFT JOIN ax_person p ON p.gml_id = n.benennt "
	."WHERE n.istbestandteilvon = $1 AND n.endet IS NULL AND p.endet IS NULL "
	."ORDER BY n.laufendenummernachdin1421;";
	// "benennt" ist leer bei "Beschrieb der Rechtsgemeinschaft".

	$v = array($gmlid); // GB-Blatt
	$resn = pg_prepare($con, "", $sqln);
	$resn = pg_execute($con, "", $v);
	if (!$resn) {
		echo "\n<p class='err'>Fehler bei Eigent&uuml;mer</p>";
		if ($dbg > 2) {echo "\n<p class='dbg'>SQL=<br>".str_replace("$1", "'".$gmlid."'", $sqln )."</p>";}
	}

	$n=0; // Z.NamNum.
	while($rown = pg_fetch_assoc($resn)) {
		$gmlnn=$rown["gml_id"];
		$namnum=kurz_namnr($rown["lfd"]);
		$rechtsg=$rown["adr"];
		$beschr=htmlentities($rown["beschr"], ENT_QUOTES, "UTF-8");
		$adrv=htmlentities($rown["adrv"], ENT_QUOTES, "UTF-8");
		$eiartkey=$rown["eigentuemerart"]; // Key
		$eiart=$rown["eiartv"]; // Value
	//	$anlass=$rown["anlass"];
		$gmlpers=$rown["gmlpers"]; // leer bei Rechtsverhältnis
		$akadem=$rown["akademischergrad"];
		$nachnam=$rown["nachnameoderfirma"];
		$vorname=$rown["vorname"];
		$nbest=$rown["namensbestandteil"];
		$gebdat=$rown["geburtsdatum"];
		$gebnam=$rown["geburtsname"];
		if (is_null($rown["zaehler"])) {
			$zaehler="";
		} else {
			$zaehler=str_replace(".", ",", $rown["zaehler"]); // Dezimal-KOMMA wenn dem Notar der Bruch nicht reicht
		}
		if (is_null($rown["nenner"]))  {
			$nenner="";
		} else {
			$nenner=str_replace(".", ",", $rown["nenner"]);
		}
		echo "\n\t<tr>";
		if($n === 0) { // 1. Zeile zum GB
			echo "\n\t\t<td class='ll'><img src='ico/Eigentuemer_2.png' width='16' height='16' alt=''> Eigent&uuml;mer:</td>"; // 1
		} else { // Folgezeile
			echo "\n\t\t<td class='ll'>&nbsp;</td>";
		}
		if ($rechtsg != "" ) { // Erbengemeinschaft usw.
			echo "\n\t\t<td colspan='2'>";
			if ($rechtsg == 9999) { // sonstiges
				echo "\n\t\t\t<p class='zus' title='Beschrieb der Rechtsgemeinschaft'>".$beschr."</p>";
			} else {
				echo "\n\t\t\t<p class='zus' title='Art der Rechtsgemeinschaft'>".$adrv."</p>";
			}
		} else { // Namensnummer
			echo "\n\t\t<td class='nanu' title='Namens-Nummer'>\n\t\t\t<p>".$namnum."&nbsp;</p>\n\t\t</td>"
			. "\n\t\t<td>";
		}

	//	if ($anlass > 0 ) {echo "<p>Anlass=".$anlass."</p>";} // Array, Entschlüsseln?

		// Andere Namensnummern? Relation: ax_namensnummer >bestehtAusRechtsverhaeltnissenZu> ax_namensnummer 
		// Die Relation 'Namensnummer' besteht aus Rechtsverhältnissen zu 'Namensnummer' sagt aus, dass mehrere Namensnummern zu einer Rechtsgemeinschaft gehören können. 
		// Die Rechtsgemeinschaft selbst steht unter einer eigenen AX_Namensnummer, die zu allen Namensnummern der Rechtsgemeinschaft eine Relation besitzt.

		$diePerson="";
		if ($akadem != "") {$diePerson=$akadem." ";}
		$diePerson.=$nachnam;
		if ($vorname != "") {$diePerson.=", ".$vorname;}
		if ($nbest != "") {$diePerson.=". ".$nbest;}
		if ($gebdat != "") {$diePerson.=", geb. ".$gebdat;}
		if ($gebnam != "") {$diePerson.=", geb. ".$gebnam;}
		$diePerson=htmlentities($diePerson, ENT_QUOTES, "UTF-8");

		if ($eiartkey == "") {$eiart="Eigent&uuml;mer" ;} // Default
		echo "\n\t\t\t<p class='geig' title='Eigent&uuml;merart: ".$eiart."'>".$diePerson."</p>\n\t\t</td>"
		."\n\t\t<td>\n\t\t\t<p class='nwlink noprint'>".DsKy($eiartkey, 'Eigent&uuml;merart-*')
		."\n\t\t\t\t<a href='".$lnkvor."alkisnamstruk.php?gkz=".$gkz."&amp;gmlid=".$gmlpers.LnkStf()
		.$lnknach."' title='vollst&auml;ndiger Name und Adresse eines Eigent&uuml;mers'>".$eiart
		." \n\t\t\t\t\t<img src='ico/Eigentuemer.png' width='16' height='16' alt=''>\n\t\t\t\t</a>\n\t\t\t</p>"
		."\n\t\t</td>\n\t</tr>";

		if ($mitadresse) { // optional
			// A d r e s s e  zur Person
			$sqla ="SELECT a.gml_id, a.ort_post, a.postleitzahlpostzustellung AS plz, a.strasse, a.hausnummer, a.bestimmungsland "
			."FROM ax_anschrift a JOIN ax_person p ON a.gml_id=ANY(p.hat) "
			."WHERE p.gml_id= $1 AND a.endet IS NULL AND p.endet IS NULL "
			."ORDER BY a.beginnt DESC LIMIT 2;";
			$v = array($gmlpers);
			$resa = pg_prepare($con, "", $sqla);
			$resa = pg_execute($con, "", $v);
			if (!$resa) {
				echo "\n\t<p class='err'>Fehler bei Adressen</p>";
				if ($dbg > 2) {echo "\n<p class='err'>SQL=<br>".str_replace("$1", "'".$gmlpers."'", $sqla)."</p>";}
			}
			$j=0;
			while($rowa = pg_fetch_assoc($resa)) {
				$j++;
				if ($j === 1) { // erste ("jüngste") Adresse anzeigen
					$gmla=$rowa["gml_id"];
					$plz=$rowa["plz"]; // integer
					if($plz === 0) {
						$plz="";
					} else {
						$plz=str_pad($plz, 5, "0", STR_PAD_LEFT);
					}
					$ort=htmlentities($rowa["ort_post"], ENT_QUOTES, "UTF-8");
					$str=htmlentities($rowa["strasse"], ENT_QUOTES, "UTF-8");
					$hsnr=$rowa["hausnummer"];
					$land=htmlentities($rowa["bestimmungsland"], ENT_QUOTES, "UTF-8");

					echo "\n\t<tr>\n\t\t<td class='ll'>&nbsp;</td>\n\t\t<td>&nbsp;</td>"
					."\n\t\t<td><p class='gadr'>";
					if ($str.$hsnr != "") {echo $str." ".$hsnr."<br>";}
					if ($plz.$ort != "") {echo $plz." ".$ort;}
					if ($land != "" and $land != "DEUTSCHLAND") {echo ", ".$land;}
					echo "</p></td>"
					."\n\t\t<td>&nbsp;</td>\n\t</tr>";
				} else { // manchmal mehrere Angaben
					echo "\n\t<tr>\n\t\t<td class='ll'>&nbsp;</td>\n\t\t<td>&nbsp;</td>"
					."\n\t\t<td><p class='dbg' title='Siehe Auskunft zur Person'>weitere Adresse</p></td>"
					."\n\t\t<td>&nbsp;</td>\n\t</tr>";
				}
			}
			pg_free_result($resa);
		}	// 'keine Adresse' kann vorkommen, z.B. "Deutsche Telekom AG"

		if ($zaehler != "") { // Anteil als eigene Tab-Zeile
			$comnt="Anteil der Berechtigten in Bruchteilen (Par. 47 GBO) an einem gemeinschaftlichen Eigentum (Grundst&uuml;ck oder Recht).";
			echo "\n\t<tr>\n\t\t<td class='ll'>&nbsp;</td>\n\t\t<td>&nbsp;</td>"
			."\n\t\t<td><p class='avh' title='".$comnt."'>".$zaehler."/".$nenner." Anteil</p></td>"
			."\n\t\t<td>&nbsp;</td>\n\t</tr>";
		}
		if ($gmlpers == "") { // KEINE Person. benennt ist leer. Das kommt vor hinter der Zeile "Erbengemeinschaft" und ist dann KEIN Fehler
			if ($dbg > 1) {echo "\n\t\t\t<p class='dbg'>Rechtsgemeinschaft = '".$rechtsg."'</p>";}
			if ($rechtsg !== '9999') {
				echo "\n<p class='err'>(Die Person mit der ID '".$gmlpers."' fehlt im Datenbestand)</p>";
			}
			echo "</td>\n\t\t<td>&nbsp;</td>\n\t</tr>";
		}
		$n++;
	}
	pg_free_result($resn);
	return $n; 
}

function fortfuehrungen($entsteh, $dbzeart, $dbzename) {
// Tabelle im Kopf von Flurstück und FS-Historie. 2 Z./Sp. Entstehung/Fortführung
// Parameter: Die DB-Spalten "zeitpunktderentstehung"[], "zeigtaufexternes_art"[] und "zeigtaufexternes_name"[]
	global $dbg, $showkey;

	echo "\n\t<table class='fsd'>" // FS-Daten 2 Spalten
	."\n\t\t<tr>\n\t\t\t<td>Entstehung</td>"
	."\n\t\t\t<td title='Zeitpunkt der Enstehung'>".$entsteh."</td>\n\t\t</tr>";
	echo "\n\t<tr>\n\t\t\t<td>";
	$arrart=explode(",", trim($dbzeart, "{}"));
	foreach($arrart AS $artval) { // Eine Zeile für jedes Element von "zeigtaufexternes_art"
		$artval=trim($artval, '"');
		$artpos=strpos($artval, '#');
		if ($artpos > 0) { // AED
			$artkey=substr($artval, $artpos + 1);
			switch ($artkey) { // keine Schlüsseltabelle?
				case '5100':
					$arttxt="Grundst&uuml;ckshinweis (aus ALB-Historie)";
					$artinfo="";
					break;
				case '5200':
					$arttxt="Entstehung des Flurst&uuml;cks";
					$artinfo="'Entstehung des Flurstücks' enthält das Jahr der Entstehung, die lfd. Nr. der Fortführung und den Schlüssel der Fortführungsart zur manuellen Recherche in den Grundbuchakten.";
					break;
				case '5300':
					$arttxt="Letzte Fortf&uuml;hrung des Flurst&uuml;cks";
					$artinfo="'Letzte Fortführung' enthält das Jahr der letzten Fortführung, die lfd. Nr. der Fortführung und den Schlüssel der Fortführungsart zur manuellen Recherche in den Grundbuchakten.";
					break;
				default:
					$arttxt=$artval;
					$artinfo="";
			}
			if ($showkey) {echo "<span class='key'><a target='_blank' title='".htmlentities($artinfo, ENT_QUOTES, "UTF-8")."' href='".$artval."'>(".$artkey.")</a></span> ";}
			echo $arttxt."<br>";
		} else {
			$artpos=strpos($artval, '/');
			if ($artpos > 0) { // ibR
				$artkey=substr($artval, $artpos + 1);
				$arttxt=substr($artval, 0, $artpos);
				echo DsKy($artkey, 'Fortf&uuml;hrungsart').$arttxt."<br>";
			}
		}
	}
	echo "</td>\n\t\t\t<td title='Jahrgang / Fortf&uuml;hrungsnummer - Fortf&uuml;hrungsart'>";
	$arrname=explode(",", trim($dbzename, "{}")); // Eine Zeile für jedes Element von "zeigtaufexternes_name"
	foreach($arrname AS $val) {echo trim($val, '"')."<br>";}
	echo "</td>\n\t\t</tr>\n\t</table>";
}

function fskenn_dbformat($fskennz) {
// Erzeugt aus dem Bindetrich-getrennten Flurstückskennzeichen "llgggg-fff-nnnn/zz.nn" oder "gggg-ff-nnn/zz" 
// das ALKIS-DB-interne Format des Flurstückskennzeichens.
	global $defland;
	$arr=explode("-", $fskennz, 4); // zerlegen
	$zgemkg=trim($arr[0]);
	if (strlen($zgemkg) === 20 and !isset($arr[1])) {
		$fskzdb=$zgemkg; // ist schon Datenbank-Feldformat
	} else { // Das Kennzeichen auseinander nehmen. 
		if (strlen($zgemkg) === 6) {
			$land=substr($zgemkg, 0, 2);
			$zgemkg=substr($zgemkg, 2, 4);
		} else { // kein schöner Land ..
			$land=$defland; // Default-Land aus config
		}
		$zflur=str_pad($arr[1], 3 , "0", STR_PAD_LEFT); // Flur-Nr
		$zfsnr=trim($arr[2]); // Flurstücks-Nr
		$zn=explode("/", $zfsnr, 2); // Bruch?
		$zzaehler=str_pad(trim($zn[0]), 5 , "0", STR_PAD_LEFT);
		if (isset($zn[1])) {
			$znenner=trim($zn[1]);
		} else {
			$znenner="";
		}
		if (trim($znenner, " 0.") === "") { // kein Bruch oder nur Nullen
			$znenner="____"; // in DB-Spalte mit Tiefstrich aufgefüllt
		} else {
			$zn=explode(".", $znenner, 2); // .00 wegwerfen
			$znenner=str_pad($zn[0], 4 , "0", STR_PAD_LEFT);
		}
		// die Teile stellengerecht wieder zusammen setzen		
		$fskzdb=$land.$zgemkg.$zflur.$zzaehler.$znenner.'__'; // FS-Kennz. Format Datenbank
	}
	return $fskzdb;
}
?>
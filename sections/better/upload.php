<?
if (!empty($_GET['userid']) && is_number($_GET['userid'])) {
	if (check_perms('users_override_paranoia')) {
		$UserID = $_GET['userid'];
	} else {
		error(403);
	}
} else {
	$UserID = $LoggedUser['ID'];
}

$Encodings = array('V0 (VBR)', 'V2 (VBR)', '320');
$EncodingKeys = array_fill_keys($Encodings, true);

// Get list of FLAC uploads
$DB->query("SELECT t.GroupID, t.ID
	FROM torrents AS t
	WHERE
	t.Format='FLAC'
	AND ((t.LogScore = '100' AND t.Media = 'CD')
		OR t.Media != 'CD')
	AND t.UserID='$UserID'");

$UploadedTorrentIDs = array_fill_keys($DB->collect('ID'), true);
$UploadedGroupIDs = $DB->collect('GroupID');

if (count($UploadedGroupIDs) == 0) {
	error("You haven't uploaded any perfect FLACs!");
}

// Create hash table
$DB->query("CREATE TEMPORARY TABLE temp_sections_better_upload
	SELECT t.GroupID,
	GROUP_CONCAT(t.Encoding SEPARATOR ' ') AS EncodingList,
	CRC32(CONCAT_WS(' ', Media, Remasteryear, Remastertitle,
		Remasterrecordlabel, Remastercataloguenumber)) AS RemIdent
	FROM torrents AS t
	WHERE t.GroupID IN(".implode(',',$UploadedGroupIDs).")
	GROUP BY t.GroupID, RemIdent");

$DB->query("SELECT GroupID FROM temp_sections_better_upload
		WHERE EncodingList NOT LIKE '%V0 (VBR)%'
		OR EncodingList NOT LIKE '%V2 (VBR)%'
		OR EncodingList NOT LIKE '%320%'");

$GroupIDs = array_fill_keys($DB->collect('GroupID'), true);

if (count($GroupIDs) == 0) {
	error('No results found.');
}

$Groups = Torrents::get_groups(array_keys($GroupIDs));
$Groups = $Groups['matches'];

$TorrentGroups = array();
foreach ($Groups as $GroupID => $Group) {
	if (empty($Group['Torrents'])) {
		unset($Groups[$GroupID]);
		continue;
	}
	foreach ($Group['Torrents'] as $Torrent) {
		$TorRemIdent = "$Torrent[Media] $Torrent[RemasterYear] $Torrent[RemasterTitle] $Torrent[RemasterRecordLabel] $Torrent[RemasterCatalogueNumber]";
		if (!isset($TorrentGroups[$Group['ID']])) {
			$TorrentGroups[$Group['ID']] = array(
				$TorRemIdent => array(
					'FlacID' => 0,
					'Formats' => array(),
					'IsSnatched' => $Torrent['IsSnatched'],
					'Medium' => $Torrent['Media'],
					'RemasterTitle' => $Torrent['RemasterTitle'],
					'RemasterYear' => $Torrent['RemasterYear'],
					'RemasterRecordLabel' => $Torrent['RemasterRecordLabel'],
					'RemasterCatalogueNumber' => $Torrent['RemasterCatalogueNumber']
				)
			);
		} elseif (!isset($TorrentGroups[$Group['ID']][$TorRemIdent])) {
			$TorrentGroups[$Group['ID']][$TorRemIdent] = array(
				'FlacID' => 0,
				'Formats' => array(),
				'IsSnatched' => $Torrent['IsSnatched'],
				'Medium' => $Torrent['Media'],
				'RemasterTitle' => $Torrent['RemasterTitle'],
				'RemasterYear' => $Torrent['RemasterYear'],
				'RemasterRecordLabel' => $Torrent['RemasterRecordLabel'],
				'RemasterCatalogueNumber' => $Torrent['RemasterCatalogueNumber']
			);
		}
		if (isset($EncodingKeys[$Torrent['Encoding']])) {
			$TorrentGroups[$Group['ID']][$TorRemIdent]['Formats'][$Torrent['Encoding']] = true;
			$Counter['existing'][$Torrent['Encoding']] += 1;
		} elseif (isset($UploadedTorrentIDs[$Torrent['ID']])) {
			$TorrentGroups[$Group['ID']][$TorRemIdent]['FlacID'] = $Torrent['ID'];
		}
	}
}
// count how many FLAC torrents we have in this list totally (key 'total')
//		 how many transcodes there are missing totally (key 'miss_total')
//		 and for each format (keys , 'miss_V0 (VBR)', 'miss_V2 (VBR)', 'miss_320')
//		 the latter happens by counting the number of existing transcodes and then subtracting because that's easier
$Counter = array();
$Counter['total'] = 0;
foreach($TorrentGroups as $Editions) {
	foreach($Editions as $Edition) {
		if($Edition['FlacID'] == 0) { continue; } // no FLAC in this edition
		$edition_miss = 0; //number of transcodes missing in this edition
		foreach($Encodings as $Encoding) {
			if(!isset($Edition['Formats'][$Encoding])) {
				++$edition_miss;
				++$Counter['miss_'.$Encoding];
			}
		}
		$Counter['miss_total'] += $edition_miss;
		$Counter['total'] += (bool)$edition_miss;
	}
}

View::show_header('Transcode Uploads');
?>
<div class="thin">
	<h3>Stats</h3>
	<div class="box pad">
		<p>
			Number of perfect FLACs you can transcode: <?=$Counter['total']?><br />
			Number of missing transcodes: <?=$Counter['miss_total']?><br />
			Number of missing V2 / V0 / 320 transcodes: <?=$Counter['miss_V2 (VBR)']?> / <?=$Counter['miss_V0 (VBR)']?> / <?=$Counter['miss_320']?>
		</p>
	</div>
	<h3>List</h3>
	<table width="100%" class="torrent_table">
		<tr class="colhead">
			<td>Torrent</td>
			<td>V2</td>
			<td>V0</td>
			<td>320</td>
		</tr>
<?
foreach ($TorrentGroups as $GroupID => $Editions) {
	$GroupInfo = $Groups[$GroupID];
	$GroupYear = $GroupInfo['Year'];
	$ExtendedArtists = $GroupInfo['ExtendedArtists'];
	$GroupCatalogueNumber = $GroupInfo['CatalogueNumber'];
	$GroupName = $GroupInfo['Name'];
	$GroupRecordLabel = $GroupInfo['RecordLabel'];
	$ReleaseType = $GroupInfo['ReleaseType'];

	if (!empty($ExtendedArtists[1]) || !empty($ExtendedArtists[4]) || !empty($ExtendedArtists[5]) || !empty($ExtendedArtists[6])) {
		unset($ExtendedArtists[2]);
		unset($ExtendedArtists[3]);
		$ArtistNames = Artists::display_artists($ExtendedArtists);
	} else {
		$ArtistNames = '';
	}

	$TagList = array();
	$TagList = explode(' ', str_replace('_', '.', $GroupInfo['TagList']));
	$TorrentTags = array();
	foreach ($TagList as $Tag) {
		$TorrentTags[] = '<a href="torrents.php?taglist='.$Tag.'">'.$Tag.'</a>';
	}
	$TorrentTags = implode(', ', $TorrentTags);
	foreach ($Editions as $RemIdent => $Edition) {
		if (!$Edition['FlacID'] || count($Edition['Formats']) == 3) {
			continue;
		}
		$DisplayName = $ArtistNames . '<a href="torrents.php?id='.$GroupID.'&amp;torrentid='.$Edition['FlacID'].'#torrent'.$Edition['FlacID'].'" title="View Torrent">'.$GroupName.'</a>';
		if($GroupYear > 0) {
			$DisplayName .= " [".$GroupYear."]";
		}
		if ($ReleaseType > 0) {
			$DisplayName .= " [".$ReleaseTypes[$ReleaseType]."]";
		}
		$DisplayName .= ' ['.$Edition['Medium'].']';

		$EditionInfo = array();
		if (!empty($Edition['RemasterYear'])) {
			$ExtraInfo = $Edition['RemasterYear'];
		} else {
			$ExtraInfo = '';
		}
		if (!empty($Edition['RemasterRecordLabel'])) {
			$EditionInfo[] = $Edition['RemasterRecordLabel'];
		}
		if (!empty($Edition['RemasterTitle'])) {
			$EditionInfo[] = $Edition['RemasterTitle'];
		}
		if (!empty($Edition['RemasterCatalogueNumber'])) {
			$EditionInfo[] = $Edition['RemasterCatalogueNumber'];
		}
		if (!empty($Edition['RemasterYear'])) {
			$ExtraInfo .= ' - ';
		}
		$ExtraInfo .= implode(' / ', $EditionInfo);
?>
		<tr class="torrent torrent_row<?=$Edition['IsSnatched'] ? ' snatched_torrent' : ''?>">
			<td>
				<span class="torrent_links_block">
					<a href="torrents.php?action=download&amp;id=<?=$Edition['FlacID']?>&amp;authkey=<?=$LoggedUser['AuthKey']?>&amp;torrent_pass=<?=$LoggedUser['torrent_pass']?>" title="Download" class="brackets">DL</a>
				</span>
				<?=$DisplayName?>
				<div class="torrent_info"><?=$ExtraInfo?></div>
				<div class="tags"><?=$TorrentTags?></div>
			</td>
			<td><?=isset($Edition['Formats']['V2 (VBR)'])?'<strong class="important_text_alt">YES</strong>':'<strong class="important_text">NO</strong>'?></td>
			<td><?=isset($Edition['Formats']['V0 (VBR)'])?'<strong class="important_text_alt">YES</strong>':'<strong class="important_text">NO</strong>'?></td>
			<td><?=isset($Edition['Formats']['320'])?'<strong class="important_text_alt">YES</strong>':'<strong class="important_text">NO</strong>'?></td>
		</tr>
<?
	}
}
?>
	</table>
</div>
<?
View::show_footer();
?>

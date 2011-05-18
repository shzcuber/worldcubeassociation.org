<?php
#----------------------------------------------------------------------
#   Initialization and page contents.
#----------------------------------------------------------------------

require( '../_header.php' );
analyzeChoices();

showDescription();

if( $chosenShow ){
  showChoices();
  doTheDarnChecking();
} else {
  echo "<p style='color:#F00;font-weight:bold'>I haven't done any checking yet, you must click 'Show' first (after optionally choosing event and/or competition).</p>";
  showChoices();
}

require( '../_footer.php' );

#----------------------------------------------------------------------
function showDescription () {
#----------------------------------------------------------------------

  echo "<p><b>This script *CAN* affect the database, namely if you tell it to.</b></p>\n\n";

  echo "<p style='color:#3C3;font-weight:bold'>New: You can now filter by competition. If you choose 'All' both for event and competition, I only show the differences (otherwise the page would be huge - btw it'll still take a long computation time).</p>\n\n";

  echo "<p>It computes regional record markers for all valid results (value>0). If a result has a stored or computed regional record marker, it is displayed. If the two markers differ, they're shown in red/green.</p>\n\n";

  echo "<p>Only strictly previous competitions (other.<b>end</b>Date &lt; this.<b>start</b>Date) are used to compare, not overlapping competitions. Thus I might wrongfully compute a too good record status (because a result was actually beaten earlier in an overlapping competition) but I should never wrongfully compute a too bad record status.</p>\n\n";

  echo "<p>Inside the same competition, results are sorted first by round, then by value, and then they're declared records on a first-come-first-served basis. This results in the records-are-updated-at-the-end-of-each-round rule you requested.</p>\n\n";

  echo "<p>A result does not need to beat another to get a certain record status, equaling is good enough.</p>\n\n";

  echo "<p>Please check it and let me know what you'd like me to do. I can modify the script to actually store the computed markers in the database, I can print SQL code to select the differing rows, I can print SQL code to update the differing rows...</p>\n\n";

  echo "<hr />\n\n";
}

#----------------------------------------------------------------------
function analyzeChoices () {
#----------------------------------------------------------------------
  global $chosenEventId, $chosenCompetitionId, $chosenShow, $chosenAnything;

  $chosenEventId        = getNormalParam( 'eventId' );
  $chosenCompetitionId  = getNormalParam( 'competitionId' );
  $chosenShow           = getBooleanParam( 'show' );

  $chosenAnything = $chosenEventId || $chosenCompetitionId;
}

#----------------------------------------------------------------------
function showChoices () {
#----------------------------------------------------------------------

  displayChoices( array(
    eventChoice( false ),
    competitionChoice( false ),
    choiceButton( true, 'show', 'Show' )
  ));
}

#----------------------------------------------------------------------
function doTheDarnChecking () {
#----------------------------------------------------------------------
  global $differencesWereFound;


  #--- Begin form and table.
  echo "<form action='check_regional_record_markers_ACTION.php' method='post'>\n";
  tableBegin( 'results', 11 );

  #--- Do the checking.
  computeRegionalRecordMarkers( 'best', 'Single' );
  computeRegionalRecordMarkers( 'average', 'Average' );

  #--- End table.
  tableEnd();

  #--- Tell the result.
  $date = wcaDate();
  noticeBox2(
    ! $differencesWereFound,
    "We completely agree.<br />$date",
    "<p>Darn! We disagree!<br />$date</p>\n<p>Choose the changes you agree with above, then click the 'Execute...' button below. It will result in something like the following. If you then go back in your browser and refresh the page, the changes should be visible.</p>\n<pre>I'm doing this:
UPDATE Results SET regionalSingleRecord='WR' WHERE id=11111
UPDATE Results SET regionalSingleRecord='ER' WHERE id=22222
UPDATE Results SET regionalSingleRecord='NR' WHERE id=33333
</pre>"
  );

  #--- If differences were found, offer to fix them.
  if( $differencesWereFound )
    echo "<center><input type='submit' value='Execute the agreed $valueName changes!' /></center>\n";

  #--- Finish the form.
  echo "</form>\n";
}

#----------------------------------------------------------------------
function computeRegionalRecordMarkers ( $valueId, $valueName ) {
#----------------------------------------------------------------------
  global $chosenAnything, $chosenCompetitionId, $differencesWereFound, $previousRecord, $pendingCompetitions, $startDate;

  # -----------------------------
  # Description of the main idea:
  # -----------------------------
  # Get all results that are potential regional records. Process them one
  # event at a time. Inside, process them one competition at a time, in
  # chronological order of start date. Each competition's results are only
  # compared against records of strictly previous competitions, not against
  # parallel competitions. For this, there are these main arrays:
  #
  # - $previousRecord[regionId] is a running collection of each region's record,
  #   covering all competitions *before* the current one.
  #
  # - $record[regionId] is based on $previousRecord and is used and updated
  #   inside the current competition.
  #
  # - $pendingCompetitions[regionId] holds $record of competitions already
  #   processed but not merged into $previousRecord. When a new competition is
  #   encountered, we merge those that ended before the new one into $previousRecord.
  #
  # - $baseRecord[eventId][regionId] is for when a user chose a specific
  #   competition to check. Then we quickly ask the database for the current
  #   region records from before that competition. This could be used for
  #   giving the user a year-option as well, but we don't have that (yet?).
  # -----------------------------

  #--- If a competition was chosen, we need all records from before it
  if ( $chosenCompetitionId ) {
    $startDate = getCompetitionValue( $chosenCompetitionId, "year*10000 + month*100 + day" );
    $results = dbQueryHandle("
      SELECT eventId, result.countryId, continentId, min($valueId) value, event.format valueFormat
      FROM Results result, Competitions competition, Countries country, Events event
      WHERE $valueId > 0
        AND competition.id = result.competitionId
        AND country.id     = result.countryId
        AND event.id       = result.eventId
        AND year*10000 + if(endMonth,endMonth,month)*100 + if(endDay,endDay,day) < $startDate
      GROUP BY eventId, countryId");
    while( $row = mysql_fetch_row( $results ) ) {
      list( $eventId, $countryId, $continentId, $value, $valueFormat ) = $row;
      if( isSuccessValue( $value, $valueFormat ))
        foreach( array( $countryId, $continentId, 'World' ) as $regionId )
          if( !$baseRecord[$eventId][$regionId] || $value < $baseRecord[$eventId][$regionId] )
            $baseRecord[$eventId][$regionId] = $value;
    }
  }
  #--- Otherwise we need the endDate of each competition
  else {
    $competitions = dbQuery("
      SELECT id, year*10000 + if(endMonth,endMonth,month)*100 + if(endDay,endDay,day) endDate
      FROM   Competitions competition");
    foreach ( $competitions as $competition )
      $endDate[$competition['id']] = $competition['endDate'];
  }

  #--- The IDs of relevant results (those already marked as region record and those that could be)
  $queryRelevantIds = "
   (SELECT id FROM Results WHERE regional${valueName}Record<>'' " . eventCondition() . competitionCondition() . ")
   UNION
   (SELECT id
    FROM
      Results result,
      (SELECT eventId, competitionId, roundId, countryId, min($valueId) value
       FROM Results
       WHERE $valueId > 0
       " . eventCondition() . competitionCondition() . "
       GROUP BY eventId, competitionId, roundId, countryId) helper
    WHERE result.eventId       = helper.eventId
      AND result.competitionId = helper.competitionId
      AND result.roundId       = helper.roundId
      AND result.countryId     = helper.countryId
      AND result.$valueId      = helper.value)";

  #--- Get the results, ordered appropriately
  $results = dbQueryHandle("
    SELECT
      year*10000 + month*100 + day startDate,
      result.id resultId,
      result.eventId,
      result.competitionId,
      result.roundId,
      result.personId,
      result.personName,
      result.countryId,
      result.regional${valueName}Record storedMarker,
      $valueId value,
      continentId,
      continent.recordName continentalRecordName,
      event.format valueFormat
    FROM
      ($queryRelevantIds) relevantIds,
      Results      result,
      Competitions competition,
      Countries    country,
      Continents   continent,
      Events       event,
      Rounds       round
    WHERE 1
      AND result.id      = relevantIds.id
      AND competition.id = result.competitionId
      AND round.id       = result.roundId
      AND country.id     = result.countryId
      AND continent.id   = country.continentId
      AND event.id       = result.eventId
    ORDER BY event.rank, startDate, competitionId, round.rank, $valueId
  ");

  #--- Process each result.
  while( $row = mysql_fetch_row( $results )){
    list( $startDate, $resultId, $eventId, $competitionId, $roundId, $personId, $personName, $countryId, $storedMarker, $value, $continentId, $continentalRecordName, $valueFormat ) = $row;

    #--- Handle failures of multi-attempts.
    if( ! isSuccessValue( $value, $valueFormat ))
      continue;

    #--- At new events, reset everything
    if ( $eventId != $currentEventId ) {
      $currentEventId = $eventId;
      $currentCompetitionId = false;
      $record = $previousRecord = $baseRecord[$eventId];
      $pendingCompetitions = array();
    }

    #--- Handle new competitions.
    if ( $competitionId != $currentCompetitionId ) {

      #--- Add the records of the previously current competition to the set of pending competition records
      if ( $currentCompetitionId )
        $pendingCompetitions[] = array( $endDate[$currentCompetitionId], $record );

      #--- Note the current competition
      $currentCompetitionId = $competitionId;

      #--- Prepare the records this competition will be based on
      $pendingCompetitions = array_filter ( $pendingCompetitions, "handlePendingCompetition" );
      $record = $previousRecord;
    }

    #--- Calculate whether it's a new region record and update the records.
    $calcedMarker = '';
    if( !$record[$countryId] || $value <= $record[$countryId] ){
      $calcedMarker = 'NR';
      $record[$countryId] = $value;
      if( !$record[$continentId] || $value <= $record[$continentId] ){
        $calcedMarker = $continentalRecordName;
        $record[$continentId] = $value;
        if( !$record['World'] || $value <= $record['World'] ){
          $calcedMarker = 'WR';
          $record['World'] = $value;
        }
      }
    }

    #--- If stored or calculated marker say it's some regional record at all...
    if( $storedMarker || $calcedMarker ){

      #--- Do stored and calculated agree? Choose colors and update list of differences.
      $same = ($storedMarker == $calcedMarker);
      $storedColor = $same ? '999' : 'F00';
      $calcedColor = $same ? '999' : '0E0';
      if( ! $same ){
        $selectedIds[] = $resultId;
        $differencesWereFound = true;
      }

      #--- If no filter was chosen, only show differences.
      if( !$chosenAnything  &&  $same )
        continue;

      #--- Highlight regions if the calculated marker thinks it's a record for them.
      $countryName = $countryId;
      $continentName = substr( $continentId, 1 );
      $worldName = 'World';
      if( $calcedMarker )
        $countryName = "<b>$countryName</b>";
      if( $calcedMarker  &&  $calcedMarker != 'NR' )
        $continentName = "<b>$continentName</b>";
      if( $calcedMarker == 'WR' )
        $worldName = "<b>$worldName</b>";

      #--- Recognize new events/rounds/competitions.
      $announceEvent = ($eventId       != $announcedEventId); $announcedEventId = $eventId;
      $announceRound = ($roundId       != $announcedRoundId); $announcedRoundId = $roundId;
      $announceCompo = ($competitionId != $announcedCompoId); $announcedCompoId = $competitionId;

      #--- If new event, announce it.
      if( $announceEvent ){
        tableCaption( false, "$eventId $valueName" );
        tableHeader( split( '\\|', 'Competition|Round|Person|Event|Country|Continent|World|Value|Stored|Computed|Agree' ),
                     array( 7 => "class='R2'" ) );
      }

      #--- If new round/competition inside an event, add a separator row.
      if( ($announceRound || $announceCompo)  &&  ! $announceEvent )
        tableRowEmpty();

      #--- Prepare the checkbox.
      $checkbox = "<input type='checkbox' name='update$valueName$resultId' value='$calcedMarker' />";

      #--- Show the result.
      tableRow( array(
        competitionLink( $competitionId, $competitionId ),
        $roundId,
        personLink( $personId, $personName ),
        $eventId,
        $countryName,
        $continentName,
        $worldName,
        formatValue( $value, $valueFormat ),
        "<span style='font-weight:bold;color:#$storedColor'>$storedMarker</span>",
        "<span style='font-weight:bold;color:#$calcedColor'>$calcedMarker</span>",
        ($same ? '' : $checkbox)
      ));
    }
  }
}

#----------------------------------------------------------------------
function handlePendingCompetition ( $pendingCompetition ) {
#----------------------------------------------------------------------
  global $previousRecord, $pendingCompetitions, $startDate;

  list( $endDate, $pendingRecord ) = $pendingCompetition;
  if ( $endDate >= $startDate ) return true;
  foreach ( $pendingRecord as $regionId => $value )
    if ( !$previousRecord[$regionId] || $pendingRecord[$regionId] < $previousRecord[$regionId] )
      $previousRecord[$regionId] = $pendingRecord[$regionId];
  return false;
}

?>
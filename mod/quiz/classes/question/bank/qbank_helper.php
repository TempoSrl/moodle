<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_quiz\question\bank;

use core_question\local\bank\question_version_status;
use core_question\local\bank\random_question_loader;
use qubaid_condition;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/accessmanager.php');
require_once($CFG->dirroot . '/mod/quiz/attemptlib.php');

/**
 * Helper class for question bank and its associated data.
 *
 * @package    mod_quiz
 * @category   question
 * @copyright  2021 Catalyst IT Australia Pty Ltd
 * @author     Safat Shahin <safatshahin@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qbank_helper {

    /**
     * Get the available versions of a question where one of the version has the given question id.
     *
     * @param int $questionid id of a question.
     * @return \stdClass[] other versions of this question. Each object has fields versionid,
     *       version and questionid. Array is returned most recent version first.
     */
    public static function get_version_options(int $questionid): array {
        global $DB;

        return $DB->get_records_sql("
                SELECT allversions.id AS versionid,
                       allversions.version,
                       allversions.questionid

                  FROM {question_versions} allversions

                 WHERE allversions.questionbankentryid = (
                            SELECT givenversion.questionbankentryid
                              FROM {question_versions} givenversion
                             WHERE givenversion.questionid = ?
                       )
                   AND allversions.status <> ?

              ORDER BY allversions.version DESC
              ", [$questionid, question_version_status::QUESTION_STATUS_DRAFT]);
    }

    
    
    public static function get_brainmaster_structure(string $userid, int $idcourse, ?int $action ): array {
        global $DB;
        // file_put_contents( 'C:\wamp64\www\moodle\allactivities_log.txt', 'executing qbank_helper/get_brainmaster_structure'. PHP_EOL, FILE_APPEND);
        // file_put_contents( 'C:\wamp64\www\moodle\allactivities_log.txt', 'quizcontext'. PHP_EOL, FILE_APPEND);
        // file_put_contents('C:\wamp64\www\moodle\allactivities_log.txt', "id_student {$userid}". PHP_EOL,FILE_APPEND);
        // file_put_contents('C:\wamp64\www\moodle\allactivities_log.txt', "idcourse {$idcourse}". PHP_EOL,FILE_APPEND);
        // file_put_contents('C:\wamp64\www\moodle\allactivities_log.txt', "action {$action}". PHP_EOL,FILE_APPEND);
        
        $url = 'http://192.168.1.175:5000/api/moodle_get_test'; // URL del web service.

        $data = json_encode([
            'id_student' => $userid,
            'id_course' => $idcourse,
            'action' => $action
        ]);

        // Usa cURL per inviare i dati al web service.
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);  // http_build_query($data)
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($data)
        ]);
        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        file_put_contents('C:\wamp64\www\moodle\allactivities_log.txt', "moodle_get_test got response {$response}". PHP_EOL, FILE_APPEND);  
        
        if ($httpcode !== 200) {
            debugging("Brainmaster: Failed to notify web service. Response: $response", DEBUG_DEVELOPER);
        }

        $ids = [];
        if ($httpcode === 200) {
            // Decodifica la risposta JSON
            $decodedResponse = json_decode($response, true); // Usa true per un array associativo
            // file_put_contents('C:\wamp64\www\moodle\allactivities_log.txt', "moodle_get_test got response {$decodedResponse}". PHP_EOL, FILE_APPEND);  
            if (isset($decodedResponse['error'])) {
                echo "Errore: " . $decodedResponse['error'];
                return null;
            } elseif (isset($decodedResponse['questions_id'])) {
                echo "Test suggerito: " . var_export($decodedResponse['questions_id'],true);
                $ids =  $decodedResponse['questions_id'];               

            } else {
                echo "Risposta non prevista: " . $response;
                return null;
            }
        } else {
            debugging("Brainmaster: Failed to notify web service. Response: $response", DEBUG_DEVELOPER);
            return  null;
        }

        if (empty($ids)) {
            $slotdata = []; // Nessun ID, quindi nessun risultato
        } else {
            // Creiamo i placeholder dinamici
            $placeholders = [];
            $values = [];
        
            foreach ($ids as $index => $id) {
                $placeholder = ':id' . $index;
                $placeholders[] = $placeholder;
                $values[$placeholder] = $id;
            }
            $ids = array_map('intval', $ids);
            list($sql_in, $values) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'id');

            // Creiamo la stringa dei placeholder per l'IN (...)
            //$placeholders_string = implode(',', $placeholders);
        
            // Query con placeholder dinamici
            //ROW_NUMBER() OVER (ORDER BY slot.id) AS slot,
            // ROW_NUMBER() OVER (ORDER BY slot.id) AS slotid,
            $sql = "
                SELECT 
                    ROW_NUMBER() OVER (ORDER BY slot.id) AS slot,
                    ROW_NUMBER() OVER (ORDER BY slot.id) AS slotid,
                    ROW_NUMBER() OVER (ORDER BY slot.id) AS page,
                    slot.maxmark,
                    1 AS requireprevious,
                    NULL AS filtercondition,
                    qv.status, 
                    qv.id AS versionid,
                    qv.version,
                    qr.version AS requestedversion,
                    qv.questionbankentryid,
                    q.id AS questionid,
                    q.*,
                    qc.id AS category,
                    qc.contextid AS contextid
                FROM {question} q
                JOIN {question_versions} qv ON q.id = qv.questionid
                JOIN {question_bank_entries} qbe ON qv.questionbankentryid = qbe.id
                LEFT JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                LEFT JOIN {question_references} qr ON  
                            qbe.id = qr.questionbankentryid 
                            AND qr.component='mod_quiz' 
                            AND qr.questionarea='slot'                                
                JOIN {quiz_slots} slot ON slot.id = qr.itemid
                JOIN {quiz} quiz ON slot.quizid = quiz.id
                JOIN {context} c ON c.instanceid = quiz.id AND c.contextlevel=80
                WHERE q.id $sql_in;
            ";
            file_put_contents('C:\wamp64\www\moodle\allactivities_log.txt', $sql . PHP_EOL, FILE_APPEND);

            // DEBUG: stampiamo query e parametri
            // echo "<pre>QUERY:\n" . $sql . "\n</pre>";
            // echo "<pre>PARAMS:\n" . print_r($values, true) . "\n</pre>";


            // Eseguiamo la query con i valori corretti
            $slotdata = $DB->get_records_sql($sql, $values);
        }
        
        // Salva la query SQL nel file.
        

        $uri = $_SERVER["REQUEST_URI"];
        echo($uri);
        

        foreach ($slotdata as $slot) {            
            self::prepare_slot($slot);            
            $temp = 'slot=' . $slot->slot . '; slotid=' . $slot->slotid . '; page=' . $slot->page . '; displaynum=' . $slot->displaynumber;
            # file_put_contents('C:\wamp64\www\moodle\allactivities_log.txt', $temp . PHP_EOL, FILE_APPEND);
            //da qui escono 5 quiz
        }
        $to_shift = array_key_first($slotdata);
        // self::shift_question($slotdata, $to_shift);
        // file_put_contents(__DIR__ . '/qbank_helper_log.txt', 'shifted '.$to_shift. PHP_EOL, FILE_APPEND);
        return $slotdata;
    }


    /**
     * Get the information about which questions should be used to create a quiz attempt.
     *
     * Each element in the returned array is indexed by slot.slot (slot number) an each object hass:
     * - All the field of the slot table.
     * - contextid for where the question(s) come from.
     * - category id for where the questions come from.
     * - For non-random questions, All the fields of the question table (but id is in questionid).
     *   Also question version and question bankentryid.
     * - For random questions, filtercondition, which is also unpacked into category, randomrecurse,
     *   randomtags, and note that these also have a ->name set and ->qtype set to 'random'.
     *
     * @param int $quizid the id of the quiz to load the data for.
     * @param \context_module $quizcontext the context of this quiz.
     * @param int|null $slotid optional, if passed only load the data for this one slot (if it is in this quiz).
     * @return array indexed by slot, with information about the content of each slot.
     */
    public static function get_question_structure(int $quizid, \context_module $quizcontext,
            int $slotid = null): array {
        global $DB;

        $params = [
            'draft' => question_version_status::QUESTION_STATUS_DRAFT,
            'quizcontextid' => $quizcontext->id,
            'quizcontextid2' => $quizcontext->id,
            'quizcontextid3' => $quizcontext->id,
            'quizid' => $quizid,
            'quizid2' => $quizid,
        ];
        $slotidtest = '';
        $slotidtest2 = '';
        if ($slotid !== null) {
            $params['slotid'] = $slotid;
            $params['slotid2'] = $slotid;
            $slotidtest = ' AND slot.id = :slotid';
            $slotidtest2 = ' AND lslot.id = :slotid2';
        }

        // Load all the data about each slot.
        $slotdata = $DB->get_records_sql("
                SELECT slot.slot,
                       slot.id AS slotid,
                       slot.page,
                       slot.maxmark,
                       slot.requireprevious,
                       qsr.filtercondition,
                       qv.status,
                       qv.id AS versionid,
                       qv.version,
                       qr.version AS requestedversion,
                       qv.questionbankentryid,
                       q.id AS questionid,
                       q.*,
                       qc.id AS category,
                       COALESCE(qc.contextid, qsr.questionscontextid) AS contextid

                  FROM {quiz_slots} slot

             -- case where a particular question has been added to the quiz.
             LEFT JOIN {question_references} qr ON qr.usingcontextid = :quizcontextid AND qr.component = 'mod_quiz'
                                        AND qr.questionarea = 'slot' AND qr.itemid = slot.id
             LEFT JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid

             -- This way of getting the latest version for each slot is a bit more complicated
             -- than we would like, but the simpler SQL did not work in Oracle 11.2.
             -- (It did work fine in Oracle 19.x, so once we have updated our min supported
             -- version we could consider digging the old code out of git history from
             -- just before the commit that added this comment.
             -- For relevant question_bank_entries, this gets the latest non-draft slot number.
             LEFT JOIN (
                   SELECT lv.questionbankentryid, MAX(lv.version) AS version
                     FROM {quiz_slots} lslot
                     JOIN {question_references} lqr ON lqr.usingcontextid = :quizcontextid2 AND lqr.component = 'mod_quiz'
                                        AND lqr.questionarea = 'slot' AND lqr.itemid = lslot.id
                     JOIN {question_versions} lv ON lv.questionbankentryid = lqr.questionbankentryid
                    WHERE lslot.quizid = :quizid2
                          $slotidtest2
                      AND lqr.version IS NULL
                      AND lv.status <> :draft
                 GROUP BY lv.questionbankentryid
             ) latestversions ON latestversions.questionbankentryid = qr.questionbankentryid

             LEFT JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                                       -- Either specified version, or latest ready version.
                                       AND qv.version = COALESCE(qr.version, latestversions.version)
             LEFT JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
             LEFT JOIN {question} q ON q.id = qv.questionid

             -- Case where a random question has been added.
             LEFT JOIN {question_set_references} qsr ON qsr.usingcontextid = :quizcontextid3 AND qsr.component = 'mod_quiz'
                                        AND qsr.questionarea = 'slot' AND qsr.itemid = slot.id

                 WHERE slot.quizid = :quizid
                       $slotidtest

              ORDER BY slot.slot
              ", $params);

        // Unpack the random info from question_set_reference.
        foreach ($slotdata as $slot) {
            prepare_slot($slot)
        }

        return $slotdata;
    }

    public static function prepare_slot(stdClass  $slot){
        // Ensure the right id is the id.
        $slot->id = $slot->slotid;

        if ($slot->filtercondition) {
            // Unpack the information about a random question.
            $filtercondition = json_decode($slot->filtercondition);
            $slot->questionid = 's' . $slot->id; // Sometimes this is used as an array key, so needs to be unique.
            $slot->category = $filtercondition->questioncategoryid;
            $slot->randomrecurse = (bool) $filtercondition->includingsubcategories;
            $slot->randomtags = isset($filtercondition->tags) ? (array) $filtercondition->tags : [];
            $slot->qtype = 'random';
            $slot->name = get_string('random', 'quiz');
            $slot->length = 1;
        } else if ($slot->qtype === null) {
            // This question must have gone missing. Put in a placeholder.
            $slot->questionid = 's' . $slot->id; // Sometimes this is used as an array key, so needs to be unique.
            $slot->category = 0;
            $slot->qtype = 'missingtype';
            $slot->name = get_string('missingquestion', 'quiz');
            $slot->maxmark = 0;
            $slot->questiontext = ' ';
            $slot->questiontextformat = FORMAT_HTML;
            $slot->length = 1;
        } else if (!\question_bank::qtype_exists($slot->qtype)) {
            // Question of unknown type found in the database. Set to placeholder question types instead.
            $slot->qtype = 'missingtype';
        } else {
            $slot->_partiallyloaded = 1;
        }
    }
    
    /**
     * Get this list of random selection tag ids from one of the slots returned by get_question_structure.
     *
     * @param \stdClass $slotdata one of the array elements returned by get_question_structure.
     * @return array list of tag ids.
     */
    public static function get_tag_ids_for_slot(\stdClass $slotdata): array {
        $tagids = [];
        foreach ($slotdata->randomtags as $taginfo) {
            [$id] = explode(',', $taginfo, 2);
            $tagids[] = $id;
        }
        return $tagids;
    }

    /**
     * Given a slot from the array returned by get_question_structure, describe the random question it represents.
     *
     * @param \stdClass $slotdata one of the array elements returned by get_question_structure.
     * @return string that can be used to display the random slot.
     */
    public static function describe_random_question(\stdClass $slotdata): string {
        global $DB;
        $category = $DB->get_record('question_categories', ['id' => $slotdata->category]);
        return \question_bank::get_qtype('random')->question_name(
               $category, $slotdata->randomrecurse, $slotdata->randomtags);
    }

    /**
     * Choose question for redo in a particular slot.
     *
     * @param int $quizid the id of the quiz to load the data for.
     * @param \context_module $quizcontext the context of this quiz.
     * @param int $slotid optional, if passed only load the data for this one slot (if it is in this quiz).
     * @param qubaid_condition $qubaids attempts to consider when avoiding picking repeats of random questions.
     * @return int the id of the question to use.
     */
    public static function choose_question_for_redo(int $quizid, \context_module $quizcontext,
            int $slotid, qubaid_condition $qubaids): int {
        $slotdata = self::get_question_structure($quizid, $quizcontext, $slotid);
        $slotdata = reset($slotdata);

        // Non-random question.
        if ($slotdata->qtype != 'random') {
            return $slotdata->questionid;
        }

        // Random question.
        $randomloader = new random_question_loader($qubaids, []);
        $newqusetionid = $randomloader->get_next_question_id($slotdata->category,
                $slotdata->randomrecurse, self::get_tag_ids_for_slot($slotdata));

        if ($newqusetionid === null) {
            throw new \moodle_exception('notenoughrandomquestions', 'quiz');
        }
        return $newqusetionid;
    }
}

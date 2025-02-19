DROP PROCEDURE IF EXISTS process_question;

DELIMITER $$

CREATE PROCEDURE process_question(IN _questionusageid BIGINT, IN consecutive INT)
proc_exit: BEGIN
    DECLARE new_qa_id bigint; 
    DECLARE new_qastep_id bigint; 
    DECLARE _qid  bigint;
    DECLARE _slot  bigint;
    DECLARE _max_slot  bigint;
    DECLARE _curr_slot  bigint;
    DECLARE _curr_id bigint; 
    DECLARE _id_last bigint;
    DECLARE _responsesummary longtext;
    DECLARE _rightanswer longtext;
	DECLARE _question_id bigint;
    declare _error_slot bigint;
    declare _n_correct_after_mistake bigint; 
    declare _qa_id bigint;
    
	SET _qid = _questionusageid; 
	SET _id_last = NULL;
	SET _slot = NULL;
    SET _responsesummary=NULL;
	SET _rightanswer = NULL;
    set _max_slot = null;
    SET _id_last=NULL;
	SET _curr_id = NULL;
    set _curr_slot = null;
	
    -- 1. individua lo slot dell'ultima risposta data
    SELECT max(slot), max(id) into  _curr_slot, _curr_id FROM mdl_question_attempts	where  questionusageid = _qid and responsesummary is not null; 

    if (_curr_slot is null) THEN
		leave proc_exit;
    END IF;    
        
    -- _max_slot e _id_last sono lo slot e l'id di "info"
    SELECT max(slot), max(id) into  _max_slot, _id_last FROM mdl_question_attempts where  questionusageid = _qid ; 
    
    -- 2. legge i valori dell'ultima risposta data 
	SELECT responsesummary,rightanswer,questionid, id INTO _responsesummary,_rightanswer,_question_id, _qa_id
	FROM mdl_question_attempts
	WHERE questionusageid = _qid and slot = _curr_slot;
	
    -- esce se c'è già una versione successiva della stessa domanda dopo questa
    if exists(select 1 from mdl_question_attempts where  questionusageid = _qid and slot > _curr_slot and 
						questionid= _question_id) THEN
                        leave proc_exit;
	END IF;
    
    set _error_slot = null;
    set _n_correct_after_mistake  = 0;
    
	-- qui esce se la risposta è corretta ma non deve
    if (_rightanswer=_responsesummary or _responsesummary is null) THEN
		-- cerca l'ultimo errore su questa domanda prima di questa
		select max(slot) into _error_slot from mdl_question_attempts where 
					questionusageid = _qid
					and _rightanswer != responsesummary and responsesummary is not null
                    and questionid=_question_id and slot <= _curr_slot;
                    
		if _error_slot is null THEN
			leave proc_exit; -- mai sbagliata
        END IF;
		
        select count(*) into _n_correct_after_mistake
				from mdl_question_attempts where 
					 questionusageid = _qid and
                     questionid = _question_id and
					_rightanswer= responsesummary and
                    slot >_error_slot;
                    
		if 	_n_correct_after_mistake>=consecutive then
        		leave proc_exit;
		end if;
    END IF;
    
	-- 3. Duplica i valori della riga avente id = @id_last in una nuova riga
	-- Nota: Questa query assume che la tabella abbia un campo `id` autoincrement.
	-- _max_slot e _id_last sono lo slot e l'id di "info"
	-- lo slot "info" è copiato in ultima posizione
	INSERT INTO mdl_question_attempts (
		questionusageid, slot, behaviour, questionid,variant,maxmark,minfraction,maxfraction,flagged,questionsummary,
				rightanswer,responsesummary,timemodified
	)
	SELECT 
		questionusageid, slot+1, behaviour, questionid,variant,maxmark,minfraction,maxfraction,
			flagged,questionsummary, rightanswer,responsesummary,timemodified
	FROM mdl_question_attempts
	WHERE id = _id_last;

	SELECT LAST_INSERT_ID() INTO new_qa_id; -- new_qa_id è la posizione della NUOVA riga di info
    
    -- mdl_question_attempt_steps di info è spostato sulla nuova posizione della riga info
    UPDATE mdl_question_attempt_steps set questionattemptid = new_qa_id where questionattemptid = _id_last;
    
    -- to comment 
    -- SELECT new_qa_id as new_qa_id, _id_last as _id_last;
    -- 4. sovrascrive i valori della vecchia ultima riga

	-- _max_slot e _id_last sono lo slot e l'id di "info"
	UPDATE mdl_question_attempts B  JOIN mdl_question_attempts A ON A.id = _curr_id
	SET B.questionsummary = A.questionsummary,
		B.questionusageid  = A.questionusageid, 
        B.behaviour= A.behaviour, 
        B.questionid = A.questionid,
        B.variant= A.variant,
        B.maxmark = A.maxmark,
        B.minfraction = A.minfraction,
        B.maxfraction = A.maxfraction,
        B.flagged = A.flagged,
        B.rightanswer = A.rightanswer,
        B.responsesummary = null, -- Reset responsesummary
        B.timemodified = A.timemodified
        -- B.slot = _max_slot
		WHERE B.id = _id_last;

	set _max_slot= _max_slot+1;
    
     -- TO COMMENT
    -- select _max_slot as _max_slot;
	UPDATE mdl_quiz_attempts SET layout = CONCAT(layout, ',', _max_slot, ',0') WHERE uniqueid = _qid;
	
	insert into mdl_question_attempt_steps (questionattemptid, sequencenumber, state, fraction, timecreated,userid)
	SELECT _id_last, 0, 'todo', 0, timecreated,userid
		FROM mdl_question_attempt_steps where questionattemptid = _curr_id and sequencenumber=0;

	select last_insert_id() into new_qastep_id;
	
	INSERT INTO mdl_question_attempt_step_data (attemptstepid, name, value )
	SELECT new_qastep_id, AA.name, AA.value
		FROM mdl_question_attempt_step_data AA
			join mdl_question_attempt_steps BB on AA.attemptstepid = BB.id 
		WHERE BB.questionattemptid = _curr_id and BB.sequencenumber=0;
        
END$$

DELIMITER ;
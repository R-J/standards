<?php
/**
 * Parses and verifies the doc comments for functions.

 * @copyright 2009-2016 Vanilla Forums Inc.
 * @license http://www.opensource.org/licenses/gpl-2.0.php GNU GPL v2
 */

/**
 * Parses and verifies the doc comments for functions.
 *
 * Verifies that :
 * <ul>
 *  <li>A comment exists</li>
 *  <li>There is a blank newline after the short description</li>
 *  <li>There is a blank newline between the long and short description</li>
 *  <li>There is a blank newline between the long description and tags</li>
 *  <li>Parameter names represent those in the method</li>
 *  <li>Parameter comments are in the correct order</li>
 *  <li>Parameter comments are complete</li>
 *  <li>A blank line is present before the first and after the last parameter</li>
 *  <li>Any throw tag must have a comment</li>
 *  <li>The tag order and indentation are correct</li>
 * </ul>
 */
class Vanilla_Sniffs_Commenting_FunctionCommentSniff implements PHP_CodeSniffer_Sniff {

    /**
     * Returns an array of tokens this test wants to listen for.
     *
     * @return array
     */
    public function register() {
        return array(T_FUNCTION);

    }//end register()


    /**
     * Processes this test, when one of its tokens is encountered.
     *
     * @param PHP_CodeSniffer_File $phpcsFile The file being scanned.
     * @param int                  $stackPtr  The position of the current token
     *                                        in the stack passed in $tokens.
     *
     * @return void
     */
    public function process(PHP_CodeSniffer_File $phpcsFile, $stackPtr) {
        $tokens = $phpcsFile->getTokens();
        $find   = PHP_CodeSniffer_Tokens::$methodPrefixes;
        $find[] = T_WHITESPACE;

        $empty = array(
            T_DOC_COMMENT_WHITESPACE,
            T_DOC_COMMENT_STAR,
        );

        $commentEnd = $phpcsFile->findPrevious($find, ($stackPtr - 1), null, true);
        if ($tokens[$commentEnd]['code'] === T_COMMENT) {
            // Inline comments might just be closing comments for
            // control structures or functions instead of function comments
            // using the wrong comment type. If there is other code on the line,
            // assume they relate to that code.
            $prev = $phpcsFile->findPrevious($find, ($commentEnd - 1), null, true);
            if ($prev !== false && $tokens[$prev]['line'] === $tokens[$commentEnd]['line']) {
                $commentEnd = $prev;
            }
        }

        if ($tokens[$commentEnd]['code'] !== T_DOC_COMMENT_CLOSE_TAG
            && $tokens[$commentEnd]['code'] !== T_COMMENT
        ) {
            $phpcsFile->addError('Missing function doc comment', $stackPtr, 'Missing');
            return;
        }

        if ($tokens[$commentEnd]['code'] === T_COMMENT) {
            $phpcsFile->addError('You must use "/**" style comments for a function comment', $stackPtr, 'WrongStyle');
            return;
        }
        $commentStart = $tokens[$commentEnd]['comment_opener'];

        if ($tokens[$commentEnd]['line'] !== ($tokens[$stackPtr]['line'] - 1)) {
            $error = 'There must be no blank lines after the function comment';
            $phpcsFile->addError($error, $commentEnd, 'SpacingAfter');
        }

        $short = $phpcsFile->findNext($empty, ($commentStart + 1), $commentEnd, true);

        // Check that a short description exists
        if ($short === false) {
            $error = 'Missing short description in class doc comment';
            $phpcsFile->addError($error, $commentStart, 'MissingShort');
            return;
        }

        // No extra newline before short description.
        if ($tokens[$short]['line'] !== $tokens[$commentStart]['line'] + 1) {
            $error = 'Doc comment short description must be on the first line';
            $phpcsFile->addError($error, ($commentStart + 1), 'SpacingBeforeShort');
        }

        // Short desc must be single line. (Also cover long desc new line before)
        $shortEnd = $short;
        for ($i = ($short + 1); $i < $commentEnd; $i++) {
            if ($tokens[$i]['code'] === T_DOC_COMMENT_STRING) {
                if ($tokens[$i]['line'] === ($tokens[$shortEnd]['line'] + 1)) {
                    $error = 'Class comment short description must be on a single line';
                    $phpcsFile->addError($error, ($commentStart + 1), 'ShortSingleLine');
                }
                break;
            }
        }

        $shortContent = $tokens[$short]['content'];
        // Short desc start with capital letter
        if (preg_match('#^(\p{Lu}|\P{L})#u', $shortContent) === 0) {
            $error = 'Doc comment short description must start with a capital letter';
            $phpcsFile->addError($error, $short, 'ShortNotCapital');
        }

        // Detect long description
        $long = $phpcsFile->findNext($empty, ($shortEnd + 1), ($commentEnd - 1), true);
        if ($long !== false) {
            if ($tokens[$long]['code'] === T_DOC_COMMENT_STRING) {

                // Long desc must start with a capital letter
                if (preg_match('/^(\p{Lu}|\P{L})/u', $tokens[$long]['content'][0]) === 0) {
                    $error = 'Doc comment long description must start with a capital letter';
                    $phpcsFile->addError($error, $long, 'LongNotCapital');
                }

                // Account for the fact that a long description might cover
                // multiple lines.
                $longContent = $tokens[$short]['content'];
                $longEnd     = $long;
                for ($i = ($long + 1); $i < $commentEnd; $i++) {
                    if ($tokens[$i]['code'] === T_DOC_COMMENT_STRING) {
                        if ($tokens[$i]['line'] === ($tokens[$longEnd]['line'] + 1)) {
                            $longContent .= $tokens[$i]['content'];
                            $longEnd      = $i;
                        } else {
                            break;
                        }
                    }
                }

            }//end if
        }

        // Ignore blocks with inheritdoc tags
        $docCommentContent = '';
        for ($i = $commentStart + 1; $i <= $commentEnd - 1; $i++) {
            $docCommentContent .= $tokens[$i]['content'];
        }

        if (stristr($docCommentContent, '{@inheritdoc}') !== false) {
            return;
        }

        if (!empty($tokens[$commentStart]['comment_tags'])) {
            foreach ($tokens[$commentStart]['comment_tags'] as $tag) {
                if ($tokens[$tag]['content'] === '@see') {
                    // Make sure the tag isn't empty.
                    $string = $phpcsFile->findNext(T_DOC_COMMENT_STRING, $tag, $commentEnd);
                    if ($string === false || $tokens[$string]['line'] !== $tokens[$tag]['line']) {
                        $error = 'Content missing for @see tag in function comment';
                        $phpcsFile->addError($error, $tag, 'EmptySees');
                    }
                }
            }

            $nbsTag = count($tokens[$commentStart]['comment_tags']);
            if ($nbsTag > 1) {

                $firstTag = $tokens[$commentStart]['comment_tags'][0];
                $prev     = $phpcsFile->findPrevious($empty, ($firstTag - 1), $commentStart, true);
                if ($tokens[$firstTag]['line'] !== ($tokens[$prev]['line'] + 2)) {
                    $error = 'There must be exactly one blank line before the first tag in a doc comment';
                    $phpcsFile->addError($error, $firstTag, 'SpacingBeforeFirstParam');
                }

                $firstParamTag = null;
                $lastParamTag = null;
                foreach ($tokens[$commentStart]['comment_tags'] as $tokenIndex) {
                    if ($tokens[$tokenIndex]['content'] === '@param') {
                        if ($firstParamTag === null) {
                            $firstParamTag = $tokenIndex;
                        }
                        $lastParamTag = $tokenIndex;
                    }
                }

                // Check that there is a single blank line before the first param.
                $prev = $phpcsFile->findPrevious($empty, ($firstParamTag - 1), $commentStart, true);
                if ($firstTag !== $firstParamTag && $tokens[$firstParamTag]['line'] !== ($tokens[$prev]['line'] + 2)) {
                    $error = 'There must be exactly one blank line before the first param tag in a doc comment';
                    $phpcsFile->addError($error, $firstParamTag, 'SpacingBeforeFirstParam');
                }

                // Check that there is a single blank line after the last param
                // but account for a multi-line param comments.
                $next = $phpcsFile->findNext(T_DOC_COMMENT_TAG, ($lastParamTag + 3), $commentEnd);
                if ($next !== false) {
                    $prev = $phpcsFile->findPrevious(array(T_DOC_COMMENT_TAG, T_DOC_COMMENT_STRING), ($next - 1), $commentStart);
                    if ($tokens[$next]['line'] !== ($tokens[$prev]['line'] + 2)) {
                        $error = 'There must be a single blank line after the last param tag';
                        $phpcsFile->addError($error, $lastParamTag, 'SpacingAfterLastParam');
                    }
                }
            }
        }


        $this->processReturn($phpcsFile, $stackPtr, $commentStart);
        $this->processThrows($phpcsFile, $stackPtr, $commentStart);
        $this->processParams($phpcsFile, $stackPtr, $commentStart);

    }//end process()


    /**
     * Process the return comment of this function comment.
     *
     * @param PHP_CodeSniffer_File $phpcsFile    The file being scanned.
     * @param int                  $stackPtr     The position of the current token
     *                                           in the stack passed in $tokens.
     * @param int                  $commentStart The position in the stack where the comment started.
     *
     * @return void
     */
    protected function processReturn(PHP_CodeSniffer_File $phpcsFile, $stackPtr, $commentStart) {
        $tokens = $phpcsFile->getTokens();

        // Skip constructor and destructor.
        $methodName      = $phpcsFile->getDeclarationName($stackPtr);
        $isSpecialMethod = ($methodName === '__construct' || $methodName === '__destruct');

        $return = null;
        foreach ($tokens[$commentStart]['comment_tags'] as $tag) {
            if ($tokens[$tag]['content'] === '@return') {
                if ($return !== null) {
                    $error = 'Only 1 @return tag is allowed in a function comment';
                    $phpcsFile->addError($error, $tag, 'DuplicateReturn');
                    return;
                }

                $return = $tag;
            }
        }

        if ($isSpecialMethod === true) {
            return;
        }

        if ($return !== null) {
            $content = $tokens[($return + 2)]['content'];
            if (empty($content) === true || $tokens[($return + 2)]['code'] !== T_DOC_COMMENT_STRING) {
                $error = 'Return type missing for @return tag in function comment';
                $phpcsFile->addError($error, $return, 'MissingReturnType');
            }
        } else {
            $error = 'Missing @return tag in function comment';
            $phpcsFile->addError($error, $tokens[$commentStart]['comment_closer'], 'MissingReturn');
        }//end if

    }//end processReturn()


    /**
     * Process any throw tags that this function comment has.
     *
     * @param PHP_CodeSniffer_File $phpcsFile    The file being scanned.
     * @param int                  $stackPtr     The position of the current token
     *                                           in the stack passed in $tokens.
     * @param int                  $commentStart The position in the stack where the comment started.
     *
     * @return void
     */
    protected function processThrows(PHP_CodeSniffer_File $phpcsFile, $stackPtr, $commentStart) {
        $tokens = $phpcsFile->getTokens();

        foreach ($tokens[$commentStart]['comment_tags'] as $pos => $tag) {
            if ($tokens[$tag]['content'] !== '@throws') {
                continue;
            }

            $exception = null;
            $comment   = null;
            if ($tokens[($tag + 2)]['code'] === T_DOC_COMMENT_STRING) {
                $matches = array();
                preg_match('/([^\s]+)(?:\s+(.*))?/', $tokens[($tag + 2)]['content'], $matches);
                $exception = $matches[1];
                if (isset($matches[2]) === true && trim($matches[2]) !== '') {
                    $comment = $matches[2];
                }
            }

            if ($exception === null) {
                $error = 'Exception type and comment missing for @throws tag in function comment';
                $phpcsFile->addError($error, $tag, 'InvalidThrows');
            } elseif ($comment === null) {
                $error = 'Comment missing for @throws tag in function comment';
                $phpcsFile->addError($error, $tag, 'EmptyThrows');
            } else {
                // Any strings until the next tag belong to this comment.
                if (isset($tokens[$commentStart]['comment_tags'][($pos + 1)]) === true) {
                    $end = $tokens[$commentStart]['comment_tags'][($pos + 1)];
                } else {
                    $end = $tokens[$commentStart]['comment_closer'];
                }
                for ($i = ($tag + 3); $i < $end; $i++) {
                    if ($tokens[$i]['code'] === T_DOC_COMMENT_STRING) {
                        $comment .= ' '.$tokens[$i]['content'];
                    }
                }

                // Short desc must start with a capital letter.
                if (preg_match('/^\p{Ll}/u', $comment) === 1) {
                    $error = 'Doc comment short description must start with a capital letter';
                    $phpcsFile->addError($error, ($tag + 2), 'ThrowsNotCapital');
                }

                // Short desc must end with a full stop.
                if (substr($comment, -1) !== '.') {
                    $error = 'Description must end with a full stop';
                    $phpcsFile->addError($error, ($tag + 2), 'MissingShort');
                }
            }
        }

    }//end processThrows()


    /**
     * Process the function parameter comments.
     *
     * @param PHP_CodeSniffer_File $phpcsFile    The file being scanned.
     * @param int                  $stackPtr     The position of the current token
     *                                           in the stack passed in $tokens.
     * @param int                  $commentStart The position in the stack where the comment started.
     *
     * @return void
     */
    protected function processParams(PHP_CodeSniffer_File $phpcsFile, $stackPtr, $commentStart) {
        $tokens = $phpcsFile->getTokens();

        // Validate params structure
        $params  = array();
        $maxType = 0;
        $maxVar  = 0;
        foreach ($tokens[$commentStart]['comment_tags'] as $pos => $tag) {
            if ($tokens[$tag]['content'] !== '@param') {
                continue;
            }

            $type      = '';
            $typeSpace = 0;
            $var       = '';
            $varSpace  = 0;
            $comment   = '';
            if ($tokens[($tag + 2)]['code'] === T_DOC_COMMENT_STRING) {
                $matches = array();
                preg_match('/([^$&]+)(?:((?:\$|&)[^\s]+)(?:(\s+)(.*))?)?/', $tokens[($tag + 2)]['content'], $matches);

                $typeLen   = strlen($matches[1]);
                $type      = trim($matches[1]);
                $typeSpace = ($typeLen - strlen($type));
                $typeLen   = strlen($type);
                if ($typeLen > $maxType) {
                    $maxType = $typeLen;
                }

                if (isset($matches[2]) === true) {
                    $var    = $matches[2];
                    $varLen = strlen($var);
                    if ($varLen > $maxVar) {
                        $maxVar = $varLen;
                    }

                    if (isset($matches[4]) === true) {
                        $varSpace = strlen($matches[3]);
                        $comment  = $matches[4];

                        // Any strings until the next tag belong to this comment.
                        if (isset($tokens[$commentStart]['comment_tags'][($pos + 1)]) === true) {
                            $end = $tokens[$commentStart]['comment_tags'][($pos + 1)];
                        } else {
                            $end = $tokens[$commentStart]['comment_closer'];
                        }

                        for ($i = ($tag + 3); $i < $end; $i++) {
                            if ($tokens[$i]['code'] === T_DOC_COMMENT_STRING) {
                                $comment .= ' '.$tokens[$i]['content'];
                            }
                        }
                    } else {
                        $error = 'Missing parameter comment';
                        $phpcsFile->addError($error, $tag, 'MissingParamComment');
                    }
                } else {
                    $error = 'Missing parameter name';
                    $phpcsFile->addError($error, $tag, 'MissingParamName');
                }//end if
            } else {
                $error = 'Missing parameter type';
                $phpcsFile->addError($error, $tag, 'MissingParamType');
            }//end if

            $params[] = array(
                'tag'        => $tag,
                'type'       => $type,
                'var'        => $var,
                'comment'    => $comment,
                'type_space' => $typeSpace,
                'var_space'  => $varSpace,
            );
        }//end foreach

        // Check for params validity and spacing
        $realParams  = $phpcsFile->getMethodParameters($stackPtr);
        $foundParams = array();

        foreach ($params as $pos => $param) {
            if ($param['var'] === '') {
                continue;
            }

            $foundParams[] = $param['var'];

            // Check number of spaces after the type.
            $spaces = ($maxType - strlen($param['type']) + 1);
            if ($param['type_space'] !== $spaces) {
                $error = 'Expected %s spaces after parameter type; %s found';
                $data  = array(
                    $spaces,
                    $param['type_space'],
                );

                $fix = $phpcsFile->addFixableError($error, $param['tag'], 'SpacingAfterParamType', $data);
                if ($fix === true) {
                    $content  = $param['type'];
                    $content .= str_repeat(' ', $spaces);
                    $content .= $param['var'];
                    $content .= str_repeat(' ', $param['var_space']);
                    $content .= $param['comment'];
                    $phpcsFile->fixer->replaceToken(($param['tag'] + 2), $content);
                }
            }

            // Make sure the param name is correct.
            if (isset($realParams[$pos]) === true) {
                $realName = $realParams[$pos]['name'];
                if ($realName !== $param['var']) {
                    $code = 'ParamNameNoMatch';
                    $data = array(
                        $param['var'],
                        $realName,
                    );

                    $error = 'Doc comment for parameter %s does not match ';
                    if (strtolower($param['var']) === strtolower($realName)) {
                        $error .= 'case of ';
                        $code   = 'ParamNameNoCaseMatch';
                    }

                    $error .= 'actual variable name %s';

                    $phpcsFile->addError($error, $param['tag'], $code, $data);
                }
            } elseif (substr($param['var'], -4) !== ',...') {
                // We must have an extra parameter comment.
                $error = 'Superfluous parameter comment';
                $phpcsFile->addError($error, $param['tag'], 'ExtraParamComment');
            }//end if

            if ($param['comment'] === '') {
                continue;
            }

            // Check number of spaces after the var name.
            $spaces = ($maxVar - strlen($param['var']) + 1);
            if ($param['var_space'] !== $spaces) {
                $error = 'Expected %s spaces after parameter name; %s found';
                $data  = array(
                    $spaces,
                    $param['var_space'],
                );

                $fix = $phpcsFile->addFixableError($error, $param['tag'], 'SpacingAfterParamName', $data);
                if ($fix === true) {
                    $content  = $param['type'];
                    $content .= str_repeat(' ', $param['type_space']);
                    $content .= $param['var'];
                    $content .= str_repeat(' ', $spaces);
                    $content .= $param['comment'];
                    $phpcsFile->fixer->replaceToken(($param['tag'] + 2), $content);
                }
            }
        }//end foreach

        $realNames = array();
        foreach ($realParams as $realParam) {
            $realNames[] = $realParam['name'];
        }

        // Report missing comments.
        $diff = array_diff($realNames, $foundParams);
        foreach ($diff as $neededParam) {
            $error = 'Doc comment for parameter "%s" missing';
            $data  = array($neededParam);
            $phpcsFile->addError($error, $commentStart, 'MissingParamTag', $data);
        }

    }
}

<?php

class PpiToken extends PpiElement { }
class PpiTokenWhitespace extends PpiToken { }
class PpiTokenPod extends PpiToken { }
class PpiTokenNumber extends PpiToken { }
class PpiTokenNumberBinary extends PpiTokenNumber { }
class PpiTokenNumberOctal extends PpiTokenNumber { }
class PpiTokenNumberHex extends PpiTokenNumber { }
class PpiTokenNumberFloat extends PpiTokenNumber { }
class PpiTokenNumberExp extends PpiTokenNumberFloat { }
class PpiTokenNumberVersion extends PpiTokenNumber { }
class PpiTokenDashedWord extends PpiToken { }
class PpiTokenMagic extends PpiTokenSymbol { }
class PpiTokenArrayIndex extends PpiToken { }
class PpiTokenQuote extends PpiToken { }
class PpiTokenQuoteSingle extends PpiTokenQuote { }
class PpiTokenQuoteDouble extends PpiTokenQuote { }
class PpiTokenQuoteLiteral extends PpiTokenQuote { }
class PpiTokenQuoteInterpolate extends PpiTokenQuote { }
class PpiTokenQuoteLike extends PpiToken { }
class PpiTokenQuoteLikeBacktick extends PpiTokenQuoteLike { }
class PpiTokenQuoteLikeCommand extends PpiTokenQuoteLike { }
class PpiTokenQuoteLikeRegexp extends PpiTokenQuoteLike { }
class PpiTokenQuoteLikeReadline extends PpiTokenQuoteLike { }
class PpiTokenRegexp extends PpiToken { }
class PpiTokenRegexpMatch extends PpiTokenRegexp { }
class PpiTokenRegexpSubstitute extends PpiTokenRegexp { }
class PpiTokenRegexpTransliterate extends PpiTokenRegexp { }
class PpiTokenHereDoc extends PpiToken { }
class PpiTokenStructure extends PpiToken { }
class PpiTokenLabel extends PpiToken { }
class PpiTokenSeparator extends PpiToken { }
class PpiTokenData extends PpiToken { }
class PpiTokenEnd extends PpiToken { }
class PpiTokenPrototype extends PpiToken { }
class PpiTokenAttribute extends PpiToken { }
class PpiTokenUnknown extends PpiToken { }

class PpiTokenCast extends PpiToken
{
    function genCode()
    {
        if (! $this->converted) {
            // Can't really convert casts like "@$abc" unless you know
            // the context, because it might need to be "count($abc)".
            // Better to check these manually.
            // Might do some unambiguous cases here.

            $this->content = "/*check*/{$this->content}";
        }
        return parent::genCode();
    }
}

class PpiTokenOperator extends PpiToken
{
    function genCode()
    {
        if (! $this->converted) {
            switch ($this->content) {
            case 'eq':
                $this->content = '===';
                break;
            case 'ne':
                $this->content = '!==';
                break;
            case 'lt':
                $this->content = '<';
                break;
            case 'gt':
                $this->content = '>';
                break;
            case '->':
                $next = $this->getNextNonWs();
                if (! ($next instanceof PpiTokenWord)) {
                    $this->cancel();
                }
                break;
            case '=~':
                return $this->cvtRegEx();
            case '!~':
                return $this->cvtRegEx(true);
            case 'x':
                return $this->cvtStrRepeat();
            }
        }

        return parent::genCode();
    }

    function cvtRegEx($neg = false)
    {
        $left = $this->getPrevNonWs();
        $right = $this->getNextNonWs();

        $var = $left->content;
        $regex = $right->content;

        // Need to escape single quotes in regex expression
        $regex = str_replace("'", "\\'", $regex);

        if (substr($regex, 0, 1) == 's') {
            // Substitute, need to split into regex and substitute

            $regex = substr($regex, 1);         // Strip the 's'
            $regex = preg_replace('/\\w*$/', '', $regex);
            $delim = substr($regex, 0, 1);
            if ($delim == '/') {
                $delim = '\\' . $delim;
            }

            // Split into two strings. Have to check for escapes. Note the
            // look-behind assertion for a backslash.
            if (preg_match("/($delim.*(?<!\\\\)$delim)(.*)$delim/", $regex,
                    $matches)) {

                $this->content = "$var = preg_replace('{$matches[1]}', " .
                    "'{$matches[2]}', $var)";
            } else {
                // Can't convert

                return parent::genCode();
            }

        } else {
            // Match

            $this->content = "preg_match('$regex', $var)";
            if ($neg) {
                $this->content = "! ({$this->content})";
            }
        }

        $left->cancelUntil($this);
        $this->next->cancelUntil($right->next);
        return parent::genCode();
    }

    private function cvtStrRepeat()
    {
        $left = $this->getPrevNonWs();
        $right = $this->getNextNonWs();
        $str = $left->content;
        $count = $right->content;

        $this->content = "str_repeat($str, $count)";
        $left->cancel();
        $right->cancel();
        return parent::genCode();
    }

}




class PpiTokenComment extends PpiToken
{
    function genCode()
    {
        if (! $this->converted) {
            $this->content = preg_replace('/^(\s*)#/', '\1//', $this->content);
        }

        return parent::genCode();
    }
}



/**
 * string like "qw(abc def)";
 */
class PpiTokenQuoteLikeWords extends PpiTokenQuoteLike
{
    function genCode()
    {
        if (! $this->converted) {
            if (preg_match('/qw\s*\((.*)\)/', $this->content, $matches)) {
                $list = explode(' ', $matches[1]);
                $this->content = '[ ' . implode(', ', array_map(function ($s) {
                    return "'$s'";
                }, $list)) . ' ]';
            }
        }

        return parent::genCode();
    }
}

/**
 * Usually a variable name
 */
class PpiTokenSymbol extends PpiToken
{
    function genCode()
    {
        if (! $this->converted) {
            $varName = $this->content;

            switch (substr($varName, 0, 1)) {
            case '$':
                // Normal variable
                break;

            case '@':
                if ($varName == '@ISA') {
                    // Special case of @ISA and just comment out

                    $varName = '//@ISA';
                } else {
                    // Array, change to normal variable

                    $varName = '$' . substr($varName, 1);
                }
                break;

            case '%':
                // Hash, change to normal variable
                $varName = '$' . substr($varName, 1);
                break;

            case '&':
                // Function, just strip off
                $varName = substr($varName, 1);
                break;

            default:
                // Other is most likely function
                break;
            }

            $path = '';
            if (strpos($varName, '::') !== false) {
                // Word has a path, convert it

                $save = '';
                if (substr($varName, 0, 1) == '$') {
                    $varName = substr($varName, 1);
                    $save = '$';
                }

                if (preg_match('/(.*)::(.*)/', $varName, $matches)) {
                    $path = '\\' . $this->cvtPackageName($matches[1]) . '::';
                    $varName = $save . $matches[2];
                }
            }

            if (substr($varName, 0, 1) == '$') {
                $this->content = $path . '$' .
                    lcfirst($this->cvtCamelCase(substr($varName, 1)));
            } else {
                $this->content = $path . lcfirst($this->cvtCamelCase($varName));
            }

            // Translate special object reference name
            if ($this->content == '$self') {
                $this->content = '$this';
            }
        }

        return parent::genCode();
    }

}




/**
 * Process general token word, this is where a lot of the action takes
 * place.
 */
class PpiTokenWord extends PpiToken
{
    function genCode()
    {
        if (! $this->converted) {
            $word = $this->content;

            if (strpos($word, '::') !== false) {
                return $this->tokenWordPackageName();
            }

            if ($this->parent instanceof PpiStatementExpression) {
                if ($this->parent->parent instanceof PpiStructureSubscript) {
                    // Might be a bareword hash index

                    return $this->quoteBareWord();
                }

                $next = $this->getNextNonWs();
                if ($next->content == '=>') {
                    return $this->quoteBareWord();
                }
            }

            if (! $this->isReservedWord($word)) {
                // Looks like some sort of function name or variable

                $this->content = lcfirst($this->cvtCamelCase($word));
                return parent::genCode();
            }

            if ($this->parent instanceof PpiStatement) {
                switch($word) {
                case 'my':          $this->tokenWordMy();           break;
                case 'split':       $this->tokenWordSplit();        break;
                case 'shift':
                    $this->convertWordWithArg('array_shift');
                    break;
                case 'pop':
                    $this->convertWordWithArg('array_pop');
                    break;
                case 'unshift':
                    $this->content = 'array_unshift';
                    break;
                case 'push':
                    $this->content = 'array_push';
                    break;
                case 'length':
                    $this->content = 'strlen';
                    break;
                case 'sub':         $this->tokenWordSub();          break;
                case 'package':     $this->tokenWordPackage();      break;
                case 'require':     $this->content = 'use';         break;
                case 'elsif':       $this->content = 'elseif';      break;
                case 'if':
                    $this->tokenWordConditionals(false, 'if');
                    break;
                case 'unless':
                    $this->tokenWordConditionals(true, 'if');
                    break;
                }
            } else {
                print "Not statement: {$this->content}\n";
            }
        }

        return parent::genCode();
    }

    private function quoteBareWord()
    {
        $word = $this->content;

        // Put quotes around bareweord
        $c = substr($word, 0, 1);
        if ($c != '"' && $c != "'") {
            $this->content = "'$word'";
        }
        return parent::genCode();
    }


    private function tokenWordSub()
    {
        $parent = $this->parent;
        // Need to look at context

        if ($parent instanceof PpiStatementVariable) {
            // Anonymous function

            $this->content = 'function ()';
        } elseif ($parent instanceof PpiStatementSub) {
            // Function context

            // Get the name of the function
            $tokens = $this->peekAhead(4);
            $name = lcfirst($this->cvtCamelCase($tokens[1]->content));
            $tokens[0]->cancel();
            $tokens[1]->cancel();

            if ($tokens[3] instanceof PpiStructureBlock) {
                // Try to figure out an argument list
                // First check for the easy case of "my ($var, $var2) = @_;"

                $obj = $tokens[3];
                $obj = $obj->next;
                $firstObj = $obj;
                $obj = $obj->next->SkipWhitespace();

                $argList = [];
                $found = false;
                $saveObj = $obj;
                if ($obj instanceof PpiStatementVariable) {
                    $max = 200;
                    while (($obj = $obj->next) !== null && --$max > 0) {
                        if ($obj->content == '@_') {
                            $found = true;
                            // Cancel semicolon and newline
                            $obj = $obj->findNewline();

                            // Skip blank lines
                            while ($obj->next->isNewline()) {
                                $obj = $obj->next;
                            }
                            break;
                        }

                        if ($obj instanceof PpiTokenSymbol) {
                            $argList[] = $var = $obj->genCode();
                            continue;
                        }

                        if ($obj->content == ';') {
                            break;
                        }
                    }
                }

                if (! $found) {
                    $argList = [];

                    // Not found, try the more complicate version. Look
                    // for lines like: "my $var = shift;"
                    $obj = $saveObj;
                    while ($obj instanceof PpiStatementVariable) {
                        $obj1 = $obj->getNextNonWs();
                        $obj2 = $obj1->getNextNonWs();
                        $obj3 = $obj2->getNextNonWs();
                        $obj4 = $obj3->getNextNonWs();
                        $obj5 = $obj4->getNextNonWs();
                        $obj = $obj5->getNextNonWs();
                        if ($obj1->content != 'my' ||
                                ! ($obj2 instanceof PpiTokenSymbol) ||
                                $obj3->content != '=' ||
                                $obj4->content != 'shift' ||
                                $obj5->content != ';') {

                            break;
                        }

                        $argList[] = $obj2->genCode();
                    }

                    if (count($argList)) {
                        $obj = $obj5;
                        while (! $obj->isNewline()) {
                            $obj = $obj->next;
                        }
                    }
                }
            }

            // Cancel out the lines with the argument list
            if (count($argList)) {
                $firstObj->cancelUntil($obj);

                // If first argument is '$self', remove it
                if ($argList[0] == '$self' || $argList[0] == '$this') {
                    array_shift($argList);
                }
            }


            $this->content = "function $name(" .
                implode(', ', $argList) . ")";
        } else {
            throw new \Exception("Bad context " . get_class($parent) .
                ", Could not convert $word\n");
        }

        return;
    }

    private function tokenWordPackageName()
    {
        // Convert package name to class name

        $name = $this->content;
        $this->content = $this->cvtPackageName($this->content);
        return parent::genCode();
    }

    private function tokenWordPackage()
    {
        // Convert package statement to class statement

        $this->content = 'class';
        $obj = $this->getNextNonWs();
        if (! preg_match('/(.*)::(.*)/', $obj->content, $matches)) {
            $ns = '';
            $className = $obj->content;
        } else {
            $ns = $this->cvtPackageName($matches[1]);
            $className = $matches[2];
        }

        $this->content = '';
        if (! empty($ns)) {
            $this->content .= "namespace $ns;\n\n";
        }
        $this->content .= "class " . $this->cvtCamelCase($className) . "\n{";

        // Put a closing brace at end of file
        $this->root->endContent = "}\n";

        // Cancel out until the semicolon
        $obj = $this;
        do {
            $obj = $obj->next;
            $obj->cancel();
        } while ($obj->content != ';');
    }

    private function killTokenAndWs()
    {
        $this->cancel();
        $obj = $this->next;
        while ($obj instanceof PpiTokenWhitespace) {
            $obj->cancel();
            $obj = $obj->next;
        }
    }

    private function tokenWordMy()
    {
        // If not within a subroutine, probably within class.
        // Change to 'private'.
        if (! $this->isWithinSub()) {
            $this->content = 'private';
            return;
        }

        // Otherwise, kill the 'my'
        $this->killTokenAndWs();

        // Scan ahead and see if there's an initializer. If so, just kill
        // the 'my' and whitespace. Otherwise add an initializer to the
        // variable.

        $peek = $this->peekAhead(3, [ 'skip_ws' => true]);
        if ($peek[1]->content == '=') {
            // Have initializer, we're done.

            return;
        }

        if ($peek[2]->content == ';') {
            $peek[2]->content = " = '';";
        }
        return;
    }

    private function tokenWordSplit()
    {
        $this->content = 'preg_match';

        // Test if we can use faster explode
        $peek = $this->peekAhead(3, [ 'skip_ws' => true]);
        if ($peek[2] instanceof PpiTokenQuote) {
            $pattern = $peek[2]->content;
            if (! preg_match('/[\\().*]/', $pattern)) {
                // Doesn't look like a pattern, use explode

                $this->content = 'explode';
            }
        }


    }

    /**
     * Check for things like "= pop @x"
     */
    private function convertWordWithArg($newWord)
    {
        $this->content = $newWord;
        $peek = $this->peekAhead(2);
        if ($peek[0] instanceof PpiTokenWhitespace &&
                $peek[1] instanceof PpiTokenSymbol) {
            $peek[0]->cancel();
            $peek[1]->startContent = '(';
            $peek[1]->endContent = ')';
        }
    }

    /**
     * Process conditional statements, such as 'if', 'until', etc
     * Particularly deals with things like "$a = 10 if $b == 20";
     */
    private function tokenWordConditionals(
        $neg,                       // Reverse condition, as with until
        $keyword)                   // Keyword to translate to
    {
        $needSwitch = false;
        $obj = $this;
        while (($obj = $obj->prev) !== null) {
            if ($obj->isWs()) {
                continue;
            }

            if ($obj instanceof PpiStructureBlock) {
                break;
            }

            if ($obj->content == ';') {
                break;
            }

            if (! empty($obj->content)) {
                // Some sort of code before

                $needSwitch = true;
                break;
            }

            $obj = $obj->prev;
        }

        if (! $needSwitch) {
            // No code before the 'if' statement.

            return;
        }

        // parent should be a statement object
        if (! ($this->parent instanceof PpiStatement)) {
            return;
        }

        // Go through the parent's children and generate the text for
        // the left half and the right half.
        foreach ($this->parent->children as $obj) {
            if ($obj !== $this) {
                $obj->genCode();
            }
        }

        $leftText = '';
        $rightText = '';
        $foundObj = false;
        $parent = $this->parent;
        foreach ($parent->children as $obj) {
            if ($obj === $this) {
                $foundObj = true;
                continue;
            }

            if (! $foundObj) {
                $leftText .= $obj->getRecursiveContent();
            } else {
                $rightText .= $obj->getRecursiveContent();
            }

            $obj->cancel();
        }

        $rightText = trim(preg_replace('/;\s*$/', '', $rightText));
        if (substr($rightText, 0, 1) != '(') {
            $rightText = "($rightText)";
        }
        if ($neg) {
            $rightText = "(! $rightText)";
        }
        $leftText = trim($leftText);

        $rightText = str_replace("\n", ' ', $rightText);
        $leftText = str_replace("\n", ' ', $leftText);
        $rightText = preg_replace('/\s+/', ' ', $rightText);
        $leftText = preg_replace('/\s+/', ' ', $leftText);

        $indent = str_repeat(' ', $parent->getIndent());
        $this->content = "$keyword $rightText {\n" .
            "$indent    $leftText;\n" .
            "$indent}";
        return;
    }



}
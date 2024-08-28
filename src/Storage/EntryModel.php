<?php

namespace Laravel\Telescope\Storage;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Telescope\Database\Factories\EntryModelFactory;
use Carbon\Carbon;
use DateTimeZone;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class EntryModel extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'telescope_entries';

    /**
     * The name of the "updated at" column.
     *
     * @var string
     */
    const UPDATED_AT = null;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'content' => 'json',
    ];

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'uuid';

    /**
     * The "type" of the auto-incrementing ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Prevent Eloquent from overriding uuid with `lastInsertId`.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * Scope the query for the given query options.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $type
     * @param  \Laravel\Telescope\Storage\EntryQueryOptions  $options
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithTelescopeOptions($query, $type, EntryQueryOptions $options)
    {
        $this->whereType($query, $type)
                ->whereBatchId($query, $options)
                ->whereTag($query, $options)
                ->whereFamilyHash($query, $options)
                ->whereBeforeSequence($query, $options)
                ->filter($query, $options);

        return $query;
    }

    /**
     * Scope the query for the given type.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $type
     * @return $this
     */
    protected function whereType($query, $type)
    {
        $query->when($type, function ($query, $type) {
            return $query->where('type', $type);
        });

        return $this;
    }

    /**
     * Scope the query for the given batch ID.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  \Laravel\Telescope\Storage\EntryQueryOptions  $options
     * @return $this
     */
    protected function whereBatchId($query, EntryQueryOptions $options)
    {
        $query->when($options->batchId, function ($query, $batchId) {
            return $query->where('batch_id', $batchId);
        });

        return $this;
    }

    /**
     * Scope the query for the given type.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  \Laravel\Telescope\Storage\EntryQueryOptions  $options
     * @return $this
     */
    protected function whereTag($query, EntryQueryOptions $options)
    {
        $the_tag = Str::of($options->tag ?? '');

        static::whereTelescope($query, $the_tag);

        return $this;
    }


    public static function whereTelescope(&$query, string $tag, bool $isSubquery = false): void
    {
        $the_tag = Str::of($tag ?? '');

        if ($isSubquery)
        {
            $query->select('uuid');
        }

        // Makes it possible to search for: status:403|Method:POST
        if ($the_tag->contains('|'))
        {
            $tags = $the_tag->explode('|')->map(fn($s) => trim($s))->filter()->values();

            $query->where(function($subquery) use ($tags)
            {
                foreach ($tags as $tag)
                {
                    $subquery->orWhereIn('uuid', function($query2) use ($tag)
                    {
                        static::whereTelescope($query2, $tag, true);
                    });
                }
            });

            return;
        }

        // Makes it possible to search for: status:403;Method:POST
        if ($the_tag->contains(';'))
        {
            $tags = $the_tag->explode(';')->map(fn($s) => trim($s))->filter()->values();

            foreach ($tags as $tag)
            {
                $query->whereIn('uuid', function($query) use ($tag)
                {
                    static::whereTelescope($query, $tag, true);
                });
            }

            return;
        }

        $query->when($tag, function($query, $tag)
        {
            $tags = Str::of($tag ?? '')->explode(',')->map(fn($s) => trim($s))->filter();

            if ($tags->isEmpty())
            {
                return $query;
            }

            /** @var Collection $tags */
            /** @var Collection $innerTags */
            list($innerTags, $tags) = $tags->partition(function($tag)
            {
                return in_array(Str::of($tag)->lower()->before(':'), [
                    'created'
                ]);
            });

            if (!$tags->isEmpty())
            {
                $query->whereIn('uuid', function($query) use ($tags, $innerTags)
                {
                    $query->select('entry_uuid')->from('telescope_entries_tags')->whereIn('tag', $tags->all());
                });
            }

            foreach ($innerTags as $subtag)
            {
                $query->where(function($query) use ($subtag)
                {
                    $subtag = Str::of($subtag)->lower()->trim();

                    $key   = $subtag->before(':');
                    $value = $subtag->after(':');

                    if ($key == 'created')
                    {
                        $operator = Str::of('=');

                        if ($value->startsWith(['<=', '>=', '==', '!=']))
                        {
                            $operator = $value->substr(0, 2);
                            $value    = $value->substr(2)->trim();
                        }
                        elseif ($value->startsWith(['<', '>', '=', '!']))
                        {
                            $operator = $value->substr(0, 1);
                            $value    = $value->substr(1)->trim();
                        }

                        $date   = static::guess($value);
                        $format = static::guessFormat($value, '-', ':');

                        // Force invalid result on invalid input
                        if (!($date && $format))
                        {
                            return $query->where('created_at', '=', '2032-01-01 00:00:00');
                        }

                        if ($operator->startsWith(['>', '<']))
                        {
                            return $query->where('created_at', $operator->toString(), $date->toDateTimeString());
                        }

                        if ($operator->startsWith(['!']))
                        {
                            return $query->where('created_at', 'NOT LIKE', '%' . $date->format($format) . '%');
                        }

                        return $query->where('created_at', 'LIKE', '%' . $date->format($format) . '%');
                    }

                    return null;
                });
            }

            return $query;
        });
    }

    /**
     * Scope the query for the given type.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  \Laravel\Telescope\Storage\EntryQueryOptions  $options
     * @return $this
     */
    protected function whereFamilyHash($query, EntryQueryOptions $options)
    {
        $query->when($options->familyHash, function ($query, $hash) {
            return $query->where('family_hash', $hash);
        });

        return $this;
    }

    /**
     * Scope the query for the given pagination options.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  \Laravel\Telescope\Storage\EntryQueryOptions  $options
     * @return $this
     */
    protected function whereBeforeSequence($query, EntryQueryOptions $options)
    {
        $query->when($options->beforeSequence, function ($query, $beforeSequence) {
            return $query->where('sequence', '<', $beforeSequence);
        });

        return $this;
    }

    /**
     * Scope the query for the given display options.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  \Laravel\Telescope\Storage\EntryQueryOptions  $options
     * @return $this
     */
    protected function filter($query, EntryQueryOptions $options)
    {
        if ($options->familyHash || $options->tag || $options->batchId) {
            return $this;
        }

        $query->where('should_display_on_index', true);

        return $this;
    }

    /**
     * Get the current connection name for the model.
     *
     * @return string
     */
    public function getConnectionName()
    {
        return config('telescope.storage.database.connection');
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory
     */
    public static function newFactory()
    {
        return EntryModelFactory::new();
    }


    /**
     * Guess & sanitize a date or datetime string format, by returning a Carbon object.
     * This identifies bogus strings, nulldates, dates exceeding a month and returns the default.
     * It also fixes invalid DST dates by returning the valid time for them (fx returning 03:30 if 02:30 was given).
     *
     * Basicly - use this if you know a string is a date and want the carbon equivalent.
     *
     * Valid separators for date part: ; : / . , -
     * Valid separators for the time part: :
     * Formats tested:
     * - dmY
     * - d-m-Y [H:i|H:i:s]
     * - Y-m-d [H:i|H:i:s]
     * - Y-m-dTH:i:sP
     * - Y-m-dTH:i:s.uP
     * - 0000-00-00 [00:00:00]
     *
     *
     *
     * @param  string|Carbon             $input     Input date/datetime string (Carbon object can be used too as it is casted to string and guess)
     * @param  mixed|null                $default   Default value to return if input is NOT a valid date/datetime
     * @param  DateTimeZone|string|null  $timezone  Parse date from a given timezone
     *
     * @return Carbon|mixed
     */
    public static function guess($input = '', $default = null, $timezone = null)
    {
        // Trim inputs - just in case...
        $input = Str::trim($input ?? '');

        // This enables dates in DST range (fx 2020-03-29 02:30:00) to be parsed and returned as the valid date (here 2020-03-29 03:30:00)
        $timezone = $timezone ?? new DateTimeZone('Europe/Copenhagen');

        $format = null;

        try
        {
            if (!$input)
            {
                throw new Exception('No input for date');
            }

            // Target format
            $format = self::guessFormat($input);

            if (!$format)
            {
                throw new Exception('Format could not be determined');
            }

            $date   = Carbon::createFromFormat($format, $input, $timezone);
            $errors = Carbon::getLastErrors();

            if ($errors['error_count'] || $errors['warning_count'])
            {
                throw new Exception('Date gives error or warning');
            }

            if (!$date->isValid())
            {
                throw new Exception('Invalid date');
            }

            // Was a date expected?
            if (Str::length($input) <= 10)
            {
                // Then reset time to midnight before returning it
                $date->startOfDay();
            }
        }
        catch (Exception $exception)
        {
            $date = $default;
        }

        return $date;
    }


    /**
     * Guess the format of a given date.
     *
     * @param  string  $input
     * @param  string  $dateSeparator
     * @param  string  $timeSeparator
     *
     * @return string|null
     */
    public static function guessFormat(string $input = '', string $dateSeparator = '#', string $timeSeparator = '#')
    {
        // Trim inputs - just in case...
        $input = Str::of($input)->trim();

        // Target format
        $format = null;


        // This could be anything from 220422 to a full length datetime
        if ($input->length() >= 6)
        {
            $date = $input->before($input->contains('T') ? 'T' : ' ');
            $time = $input->replaceFirst($date, '')->substr(1);

            $dateFormat = '';
            $timeFormat = '';

            // Lets try and separate date
            $firstSeparator = $date->substr(1, 1);
            $firstSeparator = !is_numeric($firstSeparator->__toString()) ? $firstSeparator : $date->substr(2, 1);
            $firstSeparator = !is_numeric($firstSeparator->__toString()) ? $firstSeparator : $date->substr(4, 1);
            $firstSeparator = !is_numeric($firstSeparator->__toString()) ? $firstSeparator : '';
            $firstSeparator = (string) $firstSeparator;

            $secondSeparator = $date->after($firstSeparator)->substr(1, 1);
            $secondSeparator = !is_numeric($secondSeparator->__toString()) ? $secondSeparator : $date->after($firstSeparator)->substr(1, 1);
            $secondSeparator = !is_numeric($secondSeparator->__toString()) ? $secondSeparator : $date->after($firstSeparator)->substr(2, 1);
            $secondSeparator = !is_numeric($secondSeparator->__toString()) ? $secondSeparator : $date->after($firstSeparator)->substr(3, 1);
            $secondSeparator = !is_numeric($secondSeparator->__toString()) ? $secondSeparator : $date->after($firstSeparator)->substr(4, 1);
            $secondSeparator = !is_numeric($secondSeparator->__toString()) ? $secondSeparator : '';
            $secondSeparator = (string) $secondSeparator;

            // Shortest date is something like 020422 or 220402 - we dont know
            if (!$firstSeparator && !$secondSeparator)
            {
                $day   = $date->substr(0, 2);
                $month = $date->substr(2, 2);
                $year  = $date->substr(4);

                $dateFormat = ['dmy'];

                if ($year->length() == 4)
                {
                    $dateFormat = ['dmY'];
                }

                // Obvious bugfix - Switch around month with day if months are gt 12
                if (((int) $month->__toString()) > 12)
                {
                    $dateFormat = [$dateFormat[0][1] . $dateFormat[0][0] . $dateFormat[0][2]];
                }
            }
            else
            {
                // Next short date is fx 2/4-22 or 22-4-2 or 2022-4-2
                $first  = $date->before($firstSeparator);
                $second = $date->after($firstSeparator)->before($secondSeparator);
                $third  = $date->after($firstSeparator)->after($secondSeparator);

                // Assume input is using dmY
                $dateFormat = [
                    $first->length() == 1 ? 'j' : 'd',
                    $second->length() == 1 ? 'n' : 'm',
                    $third->length() == 2 ? 'y' : 'Y',
                ];

                // Obvious bugfix - Switch around month with day if months are gt 12
                if (((int) $second->__toString()) > 12)
                {

                    $dateFormat = [$dateFormat[1], $dateFormat[0], $dateFormat[2]];
                }

                // Or wait ... maybe it is Ymd - test for year length
                if ($first->length() == 4)
                {
                    $dateFormat = [
                        $first->length() == 2 ? 'y' : 'Y',
                        $second->length() == 1 ? 'n' : 'm',
                        $third->length() == 1 ? 'j' : 'd',
                    ];

                    // Obvious bugfix - Switch around month with day if months are gt 12
                    if (((int) $second->__toString()) > 12)
                    {
                        $dateFormat = [$dateFormat[0], $dateFormat[2], $dateFormat[1]];
                    }
                }
            }


            // Could this contain a time
            if ($time->length() == 8)
            {
                $timeFormat = 'H' . $timeSeparator . 'i' . $timeSeparator . 's';
            }

            // Is this limited to only H:i?
            if ($time->length() == 5)
            {
                $timeFormat = 'H' . $timeSeparator . 'i';
            }

            // Is this limited to only H?
            if ($time->length() == 2)
            {
                $timeFormat = 'H';
            }

            if ($time->length() == 0)
            {
                $timeFormat = '';
            }


            $format = implode($dateSeparator, $dateFormat); // Note: # will match any of these characters: ; : / . , -
            $format .= ($timeFormat ? ' ' : '') . $timeFormat;
        }

        // Could be a timestamp...
        if ($input->length() == 10 && preg_match('/^[0-9]{10}$/', $input->__toString()))
        {
            $format = 'U';
        }

        // Last check - Is this a full ISO date (like '2017-07-25T15:25:16.123456+02:00' or '2018-08-27T00:00:00Z')
        if ($input->length() >= 20 && $input->contains('T'))
        {
            $format = 'Y-m-d\TH:i:sP';

            // Add milisecond?
            if ($input->after('T')->contains('.'))
            {
                $format = 'Y-m-d\TH:i:s.uP';
            }
        }

        return $format;
    }
}

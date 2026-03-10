(function($){
  function pad(n){ return (n<10?'0':'')+n; }

  // Track the last date a user interacted with (helps the "Book now" button)
  let lastSelectedDateStr = null; // YYYY-MM-DD

  function toYmd(d){
    return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate());
  }
  function formatMoney(n){ return '$' + Number(n).toFixed(2); }

  function parseDateTime(mysql){
    // mysql: "YYYY-MM-DD HH:MM:SS" in site timezone
    // interpret as local browser time; WP timezone likely matches browser but not guaranteed.
    // We'll treat it as local for display + calculations.
    var parts = mysql.split(' ');
    var d = parts[0].split('-').map(Number);
    var t = parts[1].split(':').map(Number);
    return new Date(d[0], d[1]-1, d[2], t[0], t[1], t[2]||0);
  }

  function rangesOverlap(aStart, aEnd, bStart, bEnd){
    return aStart < bEnd && aEnd > bStart;
  }

  function hhmmToMinutes(hhmm){
    var m = /^(\d{2}):(\d{2})$/.exec(hhmm);
    if(!m) return null;
    return parseInt(m[1],10)*60 + parseInt(m[2],10);
  }

  function buildTimeOptions(dateObj, extraHours, blocks){
    // dateObj: JS Date at midnight
    var inc = Number(RoxyEB.incrementMinutes || 15);
    var openMin = hhmmToMinutes(RoxyEB.openTime || '08:00');
    var closeMin = hhmmToMinutes(RoxyEB.closeTime || '24:00'); // 24:00 => 1440
    if (closeMin === 0) closeMin = 1440;
    if (RoxyEB.closeTime === '24:00') closeMin = 1440;
    if (closeMin === null) closeMin = 1440;

    var guestHours = 2 + Number(extraHours||0);
    var guestMinutes = guestHours * 60;

    // last doors-open minute such that doorsClose <= close
    var lastStart = closeMin - guestMinutes;
    if (lastStart < openMin) lastStart = openMin;

    // Lead-time limit
    var now = new Date();
    var leadHours = Number(RoxyEB.leadTimeHours || 48);
    var earliestAllowed = new Date(now.getTime() + leadHours*3600*1000);

    var options = [];
    var enabledCount = 0;
    for (var m = openMin; m <= lastStart; m += inc){
      var doorsOpen = new Date(dateObj.getFullYear(), dateObj.getMonth(), dateObj.getDate(), Math.floor(m/60), m%60, 0);
      var isDisabled = false;
      if (doorsOpen < earliestAllowed) isDisabled = true;

      var doorsClose = new Date(doorsOpen.getTime() + guestMinutes*60000);
      // Backend reserve window (B2): 30 minutes before + 30 minutes after
      var reservedStart = new Date(doorsOpen.getTime() - 30*60000);
      var reservedEnd = new Date(doorsClose.getTime() + 30*60000);

      var ok = true;
      for (var i=0;i<blocks.length;i++){
        var b = blocks[i];
        if (rangesOverlap(reservedStart, reservedEnd, b.start, b.end)) { ok = false; break; }
      }
      if (!ok) isDisabled = true;

      var label = doorsOpen.toLocaleTimeString([], {hour:'numeric', minute:'2-digit'});
      if (isDisabled) label += ' (Booked)';
      options.push({
        value: pad(doorsOpen.getHours())+':'+pad(doorsOpen.getMinutes()),
        label: label,
        disabled: !!isDisabled
      });
      if (!isDisabled) enabledCount++;
    }
    options._enabledCount = enabledCount;
    return options;
  }

  function computePricing(guestCount, extraHours){
    guestCount = Number(guestCount||0);
    extraHours = Number(extraHours||0);
    var base = guestCount <= 25 ? Number(RoxyEB.prices.under) : Number(RoxyEB.prices.over);
    var extra = extraHours * Number(RoxyEB.prices.extra);
    return {base: base, extra: extra, total: base + extra};
  }

  function openModal(dateStr, blocks){
    // dateStr: "YYYY-MM-DD"
    var $m = $('#roxy-eb-modal');
    $m.attr('aria-hidden','false');

    var dateParts = dateStr.split('-').map(Number);
    var dayMidnight = new Date(dateParts[0], dateParts[1]-1, dateParts[2], 0,0,0);

    // store selected date
    $m.data('dateStr', dateStr);
    $m.data('blocks', blocks);

    // reset errors
    $('#roxy-eb-error').hide().text('');

    // reset form fields (but keep what user typed if reopening same session)
    $('#roxy-eb-doors-open-at').val(dateStr + ' 00:00:00');

    // Keep the modal usable on mobile/list view: allow selecting a different date in-place
    var $date = $('#roxy-eb-date');
    if ($date.length){
      $date.val(dateStr);
      $date.off('change.roxy').on('change.roxy', async function(){
        var newDate = String($date.val() || '').trim();
        if (!newDate) return;
        lastSelectedDateStr = newDate;
        $m.data('dateStr', newDate);
        $('#roxy-eb-doors-open-at').val(newDate + ' 00:00:00');
        // clear selected time
        $('#roxy-eb-doors-open-time').val('');

        // fetch and rebuild options
        // NOTE: fetchBlocks returns raw items; buildTimeOptions needs normalized Date ranges.
        var newBlocksRaw = await fetchBlocks(newDate + ' 00:00:00', newDate + ' 23:59:59');
        var newBlocks = normalizeBlocks(newBlocksRaw);
        $m.data('blocks', newBlocks);

        var parts = newDate.split('-').map(Number);
        var midnight = new Date(parts[0], parts[1]-1, parts[2], 0,0,0);
        var ex = Number($('#roxy-eb-extra-hours').val() || 0);
        var newOpts = buildTimeOptions(midnight, ex, newBlocks);
        var $sel2 = $('#roxy-eb-doors-open-time');
        $sel2.empty();
        if (!newOpts.length || (newOpts._enabledCount||0) === 0){
          $sel2.append($('<option/>').val('').text('No available times'));
          $sel2.prop('disabled', true);
        } else {
          $sel2.prop('disabled', false);
          $sel2.append($('<option/>').val('').text('Select a time'));
          newOpts.forEach(function(o){
            $sel2.append($('<option/>').val(o.value).text(o.label).prop('disabled', !!o.disabled));
          });
        }
      });
    }

    // Build time options
    var extraHours = Number($('#roxy-eb-extra-hours').val() || 0);
    var opts = buildTimeOptions(dayMidnight, extraHours, blocks);
    var $sel = $('#roxy-eb-doors-open-time');
    $sel.empty();
    if (!opts.length || (opts._enabledCount||0) === 0){
      $sel.append($('<option/>').val('').text('No available times'));
      $sel.prop('disabled', true);
    } else {
      $sel.prop('disabled', false);
      $sel.append($('<option/>').val('').text('Select a time'));
      opts.forEach(function(o){
        $sel.append($('<option/>').val(o.value).text(o.label).prop('disabled', !!o.disabled));
      });
    }
    updatePricingUI();
  }

  function closeModal(){
    $('#roxy-eb-modal').attr('aria-hidden','true');
  }

  function gatherBooking(){
    var dateStr = $('#roxy-eb-modal').data('dateStr');
    var timeVal = $('#roxy-eb-doors-open-time').val();
    if (!dateStr || !timeVal) return null;

    var extraHours = Number($('#roxy-eb-extra-hours').val() || 0);
    var doorsOpenAt = dateStr + ' ' + timeVal + ':00';

    return {
      first_name: $('input[name="first_name"]').val().trim(),
      last_name: $('input[name="last_name"]').val().trim(),
      email: $('input[name="email"]').val().trim(),
      phone: $('input[name="phone"]').val().trim(),
      guest_count: Number($('input[name="guest_count"]').val() || 0),
      doors_open_at: doorsOpenAt,
      extra_hours: extraHours,
      event_format: $('#roxy-eb-event-format').val(),
      movie_title: $('input[name="movie_title"]').val().trim(),
      live_description: $('textarea[name="live_description"]').val().trim(),
      notes: $('textarea[name="notes"]').val().trim(),
      visibility: $('#roxy-eb-visibility').val()
    };
  }

  function updatePricingUI(){
    var guestCount = Number($('input[name="guest_count"]').val() || 0);
    var extraHours = Number($('#roxy-eb-extra-hours').val() || 0);
    var p = computePricing(guestCount || 1, extraHours);
    $('#roxy-eb-pricing').html(
      '<div><strong>Estimated total:</strong> ' + formatMoney(p.total) + '</div>' +
      '<div style="margin-top:6px; font-size:13px; color:#555;">Base: ' + formatMoney(p.base) + ' • Extra hours: ' + formatMoney(p.extra) + '</div>'
    );

    // If extra hours changed, rebuild time options (availability depends on duration)
    var dateStr = $('#roxy-eb-modal').data('dateStr');
    var blocks = $('#roxy-eb-modal').data('blocks') || [];
    if (dateStr){
      var dp = dateStr.split('-').map(Number);
      var dayMidnight = new Date(dp[0], dp[1]-1, dp[2], 0,0,0);
      var opts = buildTimeOptions(dayMidnight, extraHours, blocks);
      var $sel = $('#roxy-eb-doors-open-time');
      var cur = $sel.val();
      $sel.empty();
      if (!opts.length || (opts._enabledCount||0) === 0){
        $sel.append($('<option/>').val('').text('No available times'));
        $sel.prop('disabled', true);
      } else {
        $sel.prop('disabled', false);
        $sel.append($('<option/>').val('').text('Select a time'));
        opts.forEach(function(o){
          $sel.append($('<option/>').val(o.value).text(o.label).prop('disabled', !!o.disabled));
        });
        // keep selection if still valid
        if (cur && opts.some(o => o.value === cur && !o.disabled)) $sel.val(cur);
      }
    }
  }

  function toggleFormatFields(){
    var fmt = $('#roxy-eb-event-format').val();
    if (fmt === 'movie'){
      $('#roxy-eb-movie-title-wrap').show();
      $('#roxy-eb-live-desc-wrap').hide();
    } else {
      $('#roxy-eb-movie-title-wrap').hide();
      $('#roxy-eb-live-desc-wrap').show();
    }
  }

  function fetchBlocks(startISO, endISO){
    return $.getJSON(RoxyEB.ajaxUrl, {
      action: 'roxy_eb_calendar_blocks',
      nonce: RoxyEB.nonce,
      start: startISO,
      end: endISO
    }).then(function(resp){
      if (!resp || !resp.success) throw new Error((resp && resp.data && resp.data.message) || 'Could not load availability');
      return resp.data.items || [];
    });
  }

  function toISODateTimeLocal(d){
    return d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate())+'T'+pad(d.getHours())+':'+pad(d.getMinutes())+':'+pad(d.getSeconds());
  }

  function normalizeBlocks(items){
    // Convert server mysql strings to JS Date ranges
    return items.map(function(it){
      return {
        kind: it.kind,
        title: it.title,
        visibility: it.visibility,
        doors_open_at: it.doors_open_at,
        start: parseDateTime(it.start),
        end: parseDateTime(it.end)
      };
    });
  }

  function formatVisibility(vis){
    return (vis === 'public') ? 'Public' : 'Private';
  }

  function parseMysqlToDate(mysql){
    if (!mysql) return null;
    return parseDateTime(mysql);
  }

  $(function(){
    // close handlers
    $(document).on('click', '[data-roxy-eb-close]', function(){ closeModal(); });
    $(document).on('keydown', function(e){ if(e.key === 'Escape') closeModal(); });

    $(document).on('change', '#roxy-eb-extra-hours, input[name="guest_count"]', updatePricingUI);
    $(document).on('change', '#roxy-eb-event-format', function(){ toggleFormatFields(); });
    toggleFormatFields();
    updatePricingUI();

    // form submit -> start booking (adds to cart and redirects)
    $('#roxy-eb-form').on('submit', function(e){
      e.preventDefault();
      var booking = gatherBooking();
      if (!booking){
        $('#roxy-eb-error').show().text('Please select a date and time.');
        return;
      }
      $('#roxy-eb-error').hide().text('');
      $('.roxy-eb-btn--primary').prop('disabled', true).text('Working...');

      $.post(RoxyEB.ajaxUrl, {
        action: 'roxy_eb_start_booking',
        nonce: RoxyEB.nonce,
        booking: booking
      }).done(function(resp){
        if (resp && resp.success && resp.data && resp.data.redirect){
          window.location.href = resp.data.redirect;
        } else {
          var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Could not start checkout.';
          $('#roxy-eb-error').show().text(msg);
        }
      }).fail(function(){
        $('#roxy-eb-error').show().text('Network error. Please try again.');
      }).always(function(){
        $('.roxy-eb-btn--primary').prop('disabled', false).text('Continue to checkout');
      });
    });

    // calendar init
    var el = document.getElementById('roxy-eb-calendar');
    if (!el || !window.FullCalendar) return;

    var calendar;
    calendar = new FullCalendar.Calendar(el, {
      // List view hides empty dates; default to month so mobile is usable.
      initialView: 'dayGridMonth',
      height: 'auto',
      headerToolbar: {
        left: 'prev,next today',
        center: 'title',
        // Roxy decision: list view removed (not interactive / not useful for booking)
        right: 'dayGridMonth,timeGridWeek'
      },
      buttonText: { dayGridMonth: 'month', timeGridWeek: 'week' },
      selectable: false,
      dayMaxEvents: true,
      nowIndicator: true,
      timeZone: 'local',
      eventDisplay: 'block',
      // We render our own titles like "6:00 PM — Public".
      // Disable FullCalendar's automatic time prefix in Month/List/Week.
      displayEventTime: false,
      events: function(fetchInfo, success, failure){
        var viewType = (calendar && calendar.view && calendar.view.type) ? calendar.view.type : '';
        // fetchInfo has start/end as Date
        fetchBlocks(toISODateTimeLocal(fetchInfo.start), toISODateTimeLocal(fetchInfo.end)).then(function(items){
          // Convert to FullCalendar events:
          // Views:
          // - timeGridWeek: render reserved windows as full-height blocks (readable)
          // - dayGridMonth/listMonth: keep compact labels + subtle background shading
          var ev = [];
          items.forEach(function(it){
            // IMPORTANT: never expose internal titles (e.g., admin notes like "Pizza Party") on the public calendar.

            var bg = it.kind === 'showtime'
              ? 'rgba(255,193,7,0.22)'
              : 'rgba(108,117,125,0.18)';

            // Label: Doors-open time + Private/Public only.
            var labelStart = null;
            var visibility = it.visibility;
            if (it.kind === 'booking') {
              labelStart = parseMysqlToDate(it.doors_open_at);
            } else if (it.kind === 'block') {
              labelStart = parseMysqlToDate(it.start);
            } else if (it.kind === 'showtime') {
              labelStart = parseMysqlToDate(it.start);
              visibility = 'public';
            }
            if (!labelStart) return;

            // IMPORTANT: List/Week views already render a time column.
            // To avoid showing time twice, we only put Public/Private in the title.
            var visLabel = formatVisibility(visibility);
            var title = visLabel;

            if (viewType === 'timeGridWeek') {
              // Week view: draw a full reserved block (background) plus a full-height label.
              // This avoids tiny 15-minute bars and makes the label readable.
              // Background spans the reserved window (true conflict window).
              // Use Date objects (instead of strings) so FullCalendar consistently computes
              // the correct height in timeGridWeek across browsers.
              var reservedStartDate = parseMysqlToDate(it.start);
              var reservedEndDate   = parseMysqlToDate(it.end);

              // Foreground label starts at DOORS OPEN (so the time column is correct)
              // but spans through the reserved end, so the visible bar is large/readable.
              var doorsOpenDate = labelStart;

              // Background block spanning the reserved window.
              ev.push({
                title: '',
                start: reservedStartDate,
                end: reservedEndDate,
                allDay: false,
                display: 'background',
                backgroundColor: bg,
                borderColor: 'transparent'
              });

              // Foreground label spanning the reserved window.
              ev.push({
                title: title,
                start: doorsOpenDate,
                end: reservedEndDate,
                allDay: false,
                backgroundColor: '#4c8bf5',
                borderColor: 'transparent',
                textColor: '#111',
                classNames: ['roxy-eb-booking-fg']
              });
            } else {
              // Month/List: subtle shading for the reserved window + compact label
              ev.push({
                title: '',
                start: it.start.replace(' ', 'T'),
                end: it.end.replace(' ', 'T'),
                display: 'background',
                backgroundColor: bg,
                borderColor: 'transparent'
              });
              // Compact label (15 minutes) so it appears as a neat badge in Month/List.
              var labelEnd = new Date(labelStart.getTime() + 15*60000);
              ev.push({
                title: title,
                start: labelStart,
                end: labelEnd,
                allDay: false,
                backgroundColor: '#4c8bf5',
                borderColor: 'transparent',
                textColor: '#111'
              });
            }
          });
          success(ev);
        }).catch(function(err){
          failure(err);
        });
      },
      dateClick: function(info){
        // When user clicks a date, fetch blocks for that day and open modal
        var day = info.date;
        var start = new Date(day.getFullYear(), day.getMonth(), day.getDate(), 0,0,0);
        var end = new Date(day.getFullYear(), day.getMonth(), day.getDate()+1, 0,0,0);

        fetchBlocks(toISODateTimeLocal(start), toISODateTimeLocal(end)).then(function(items){
          var blocks = normalizeBlocks(items);
          var dateStr = day.getFullYear()+'-'+pad(day.getMonth()+1)+'-'+pad(day.getDate());
          lastSelectedDateStr = dateStr;
          openModal(dateStr, blocks);
        }).catch(function(){
          $('#roxy-eb-error').show().text('Could not load availability for that day. Please try again.');
        });
      }
    });
    calendar.render();

    // "Book now" button: especially important on mobile when users are in list view.
    $(document).off('click.roxy', '#roxy-eb-book-now').on('click.roxy', '#roxy-eb-book-now', function(){
      var dateStr = lastSelectedDateStr || toYmd(calendar.getDate());
      lastSelectedDateStr = dateStr;
      fetchBlocks(dateStr + ' 00:00:00', dateStr + ' 23:59:59').then(function(items){
        openModal(dateStr, normalizeBlocks(items));
      }).catch(function(){
        openModal(dateStr, []);
      });
    });
  });
})(jQuery);

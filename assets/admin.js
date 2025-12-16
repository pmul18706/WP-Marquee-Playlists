;(function($){
  function nowLocalInputValue(){
    var d = new Date();
    function pad(n){ return (n < 10 ? '0' : '') + n; }
    return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  }

function reindex(){
  $('#wpmq-items .wpmq-item').each(function(i){
    $(this).attr('data-index', i);
    setInactiveStyle($(this));
    $(this).find('[data-name]').each(function(){
      var name = $(this).attr('data-name').replace(/__INDEX__/g, i);
      $(this).attr('name', name);
    });
  });
}

  function setInactiveStyle($item){
  var on = $item.find('.wpmq-active').is(':checked');
  $item.toggleClass('is-inactive', !on);
}

function sortInactiveToBottom(){
  var $wrap = $('#wpmq-items');
  var $items = $wrap.children('.wpmq-item').get();

  $items.sort(function(a,b){
    var aOn = $(a).find('.wpmq-active').is(':checked') ? 1 : 0;
    var bOn = $(b).find('.wpmq-active').is(':checked') ? 1 : 0;
    // active first
    return bOn - aOn;
  });

  $.each($items, function(_, el){ $wrap.append(el); });
}


  function bindItem($item){
      $item.find('.wpmq-active').off('change').on('change', function(){
  setInactiveStyle($item);
  sortInactiveToBottom();
  reindex();
});

    $item.find('.wpmq-pick').off('click').on('click', function(e){
      e.preventDefault();
      if(typeof wp === 'undefined' || !wp.media) {
        alert('Media library failed to load. Check browser console for errors.');
        return;
      }
      var $wrap = $(this).closest('.wpmq-item');

      var frame = wp.media({
        title: 'Select image',
        library: { type: 'image' },
        button: { text: 'Use this image' },
        multiple: false
      });

      frame.on('select', function(){
        var att = frame.state().get('selection').first().toJSON();
        $wrap.find('input.wpmq-image-id').val(att.id);
        $wrap.find('img.wpmq-thumb').attr('src', att.url);
        $wrap.find('.wpmq-image-title').text(att.filename || att.title || '');
      });

      frame.open();
    });

    $item.find('.wpmq-remove').off('click').on('click', function(e){
      e.preventDefault();
      if(confirm('Remove this item?')) $(this).closest('.wpmq-item').remove();
      reindex();
    });
  }

  $(document).ready(function(){
    $('#wpmq-items .wpmq-item').each(function(){ bindItem($(this)); });

    $('#wpmq-add-item').off('click').on('click', function(e){
      e.preventDefault();
      var tpl = $('#wpmq-item-template').html();
      var $new = $(tpl);
      $new.find('input[type=datetime-local].wpmq-start').val(nowLocalInputValue());
      $('#wpmq-items').append($new);
      bindItem($new);
      reindex();
    });

    $('#wpmq-items').on('change', 'input,select,textarea', function(){ reindex(); });

    reindex();
  });
})(jQuery);

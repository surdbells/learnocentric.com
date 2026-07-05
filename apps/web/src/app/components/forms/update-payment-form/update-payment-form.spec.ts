import { ComponentFixture, TestBed } from '@angular/core/testing';

import { UpdatePaymentForm } from './update-payment-form';

describe('UpdatePaymentForm', () => {
  let component: UpdatePaymentForm;
  let fixture: ComponentFixture<UpdatePaymentForm>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [UpdatePaymentForm]
    })
    .compileComponents();

    fixture = TestBed.createComponent(UpdatePaymentForm);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
